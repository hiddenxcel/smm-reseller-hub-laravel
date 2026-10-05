<?php

namespace App\Http\Controllers\Admin;

use App\Models\BotMessage;
use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\Tenant;
use App\Services\Admin\NumberActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The numbers resellers can rent.
 *
 * One page, because the whole job — add a number, check it works, price it,
 * watch who has it, take it back — has to be possible without opening a
 * terminal or touching the database. The page also says whether the platform
 * is able to receive messages at all, since a perfectly entered number is
 * useless behind a webhook that rejects everything.
 */
class NumbersController extends AdminController
{
    public function index(): Response
    {
        $this->authorise('billing.view');

        $numbers = PlatformNumber::orderByRaw("case status when 'rented' then 0 when 'available' then 1 else 2 end")
            ->orderBy('country')
            ->orderBy('display_number')
            ->get();

        $rentals = NumberRental::withoutTenantScope()
            ->whereIn('platform_number_id', $numbers->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('platform_number_id');

        $tenants = Tenant::whereIn(
            'id',
            $rentals->flatten()->where('status', 'active')->pluck('tenant_id')->unique(),
        )->get()->keyBy('id');

        $rows = $numbers->map(function (PlatformNumber $number) use ($rentals, $tenants) {
            $history = $rentals->get($number->id, collect());
            $active = $history->firstWhere('status', 'active');
            $tenant = $active ? $tenants->get($active->tenant_id) : null;

            return [
                'id' => $number->id,
                'displayNumber' => $number->display_number,
                'phoneNumberId' => $number->phone_number_id,
                'wabaId' => $number->waba_id,
                'country' => $number->country,
                'countryCode' => $number->country_code,
                'price' => (float) $number->monthly_cost,
                'status' => $number->status,
                // Never the token. Whether there is one, and its last four
                // characters so two can be told apart when rotating.
                'tokenSaved' => filled($number->cloud_api_token_enc),
                'tokenHint' => filled($number->cloud_api_token_enc)
                    ? substr((string) $number->cloud_api_token_enc, -4)
                    : null,
                'rentedTo' => $tenant ? [
                    'id' => $tenant->id,
                    'business' => $tenant->business_name,
                    'email' => $tenant->email,
                    'since' => $active->starts_at?->toIso8601String(),
                ] : null,
                'timesRented' => $history->count(),
                'addedAt' => $number->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return Inertia::render('Admin/Numbers/Index', [
            'numbers' => $rows,
            'stats' => [
                'total' => $numbers->count(),
                'available' => $numbers->where('status', 'available')->count(),
                'rented' => $numbers->where('status', 'rented')->count(),
                'suspended' => $numbers->where('status', 'suspended')->count(),
                'countries' => $numbers->pluck('country')->filter()->unique()->count(),
                // What the numbers currently out on rent are worth, one-time.
                'rentedValue' => (float) $numbers->where('status', 'rented')->sum('monthly_cost'),
            ],
            'setup' => $this->setup(),
            'currency' => NumberActions::currency(),
            'canManage' => $this->admin()->can('billing.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate($this->rules() + [
            'token' => ['required', 'string', 'max:1000'],
        ]);

        $number = NumberActions::create($validated);

        return back()->with('success', "{$number->display_number} added — resellers can rent it now.");
    }

    public function update(Request $request, PlatformNumber $number): RedirectResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate($this->rules($number) + [
            // Blank keeps the saved token.
            'token' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            NumberActions::update($number, $validated);
        } catch (RuntimeException $e) {
            return back()->withErrors(['phone_number_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Number updated.');
    }

    public function act(PlatformNumber $number, string $action): RedirectResponse
    {
        $this->authorise('billing.manage');

        try {
            switch ($action) {
                case 'suspend':
                    NumberActions::suspend($number);

                    return back()->with('success', "{$number->display_number} is out of the pool. Nobody can rent it until you restore it.");

                case 'restore':
                    NumberActions::restore($number);

                    return back()->with('success', "{$number->display_number} is available to rent again.");

                case 'release':
                    $released = NumberActions::release($number);

                    return back()->with(
                        'success',
                        $released['tenant']
                            ? "{$number->display_number} taken back from {$released['tenant']}. Their bot on it has stopped."
                            : "{$number->display_number} is back in the pool.",
                    );
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        abort(404);
    }

    public function destroy(PlatformNumber $number): RedirectResponse
    {
        $this->authorise('billing.manage');

        try {
            NumberActions::delete($number);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Number deleted.');
    }

    /**
     * Check a Phone number ID and token against Meta, without saving anything.
     *
     * Takes a saved number's id instead of a token when editing, so the
     * stored credential can be tested without it ever reaching the browser.
     */
    public function verify(Request $request): JsonResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:50'],
            'token' => ['nullable', 'string', 'max:1000'],
            'number_id' => ['nullable', 'integer'],
        ]);

        $token = $validated['token'] ?? null;

        if (blank($token) && filled($validated['number_id'] ?? null)) {
            $token = PlatformNumber::find($validated['number_id'])?->cloud_api_token_enc;
        }

        if (blank($token)) {
            return response()->json(['ok' => false, 'error' => 'Paste the access token to test it.']);
        }

        return response()->json(NumberActions::verify($validated['phone_number_id'], (string) $token));
    }

    /**
     * Whether this platform can actually receive a message, in plain terms.
     *
     * The three things that have each, in turn, made a perfectly good number
     * silent: no app secret on the server (every message refused), a webhook
     * never subscribed to `messages` (nothing ever sent), and no numbers at all.
     *
     * @return array<string, mixed>
     */
    private function setup(): array
    {
        $lastInbound = BotMessage::withoutTenantScope()
            ->where('direction', 'in')
            ->latest('id')
            ->value('created_at');

        return [
            'webhookUrl' => route('webhooks.whatsapp'),
            'verifyToken' => (string) config('services.meta.verify_token'),
            'appSecretSet' => filled(config('services.meta.app_secret')),
            'verifyTokenSet' => filled(config('services.meta.verify_token')),
            'lastMessageAt' => $lastInbound ? \Illuminate\Support\Carbon::parse($lastInbound)->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function rules(?PlatformNumber $number = null): array
    {
        return [
            'display_number' => [
                'required', 'string', 'max:30',
                // Compared after normalising, so "+255…" and "255…" cannot both exist.
                function (string $attribute, mixed $value, \Closure $fail) use ($number) {
                    $normal = NumberActions::normalise((string) $value);

                    if ($normal === '' || strlen($normal) < 8) {
                        $fail('That does not look like a phone number.');

                        return;
                    }

                    $taken = PlatformNumber::where('display_number', $normal)
                        ->when($number, fn ($q) => $q->whereKeyNot($number->id))
                        ->exists();

                    if ($taken) {
                        $fail('That number is already in the pool.');
                    }
                },
            ],
            'phone_number_id' => [
                'required', 'string', 'max:50',
                Rule::unique('platform_numbers', 'phone_number_id')->ignore($number?->id),
            ],
            'waba_id' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:60'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
