<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\BotService;
use App\Models\TenantPanel;
use App\Services\Catalogue\ServiceFeatures;
use App\Services\Panel\ServiceCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportServicesController extends Controller
{
    public function __construct(private ServiceCatalogue $catalogue) {}

    /**
     * Import the services the reseller ticked, each with the price they set.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'panel_id' => ['required', 'integer'],
            'services' => ['required', 'array', 'min:1'],
            'services.*.provider_service_id' => ['required', 'string', 'max:50'],
            'services.*.name' => ['required', 'string', 'max:190'],
            'services.*.platform' => ['required', 'string', 'max:50'],
            'services.*.category' => ['nullable', 'string', 'max:80'],
            'services.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            // A zero price would have the bot sell for nothing.
            'services.*.my_price' => ['required', 'numeric', 'gt:0'],
            'services.*.min_quantity' => ['required', 'integer', 'min:1'],
            'services.*.max_quantity' => ['required', 'integer', 'min:1'],
        ]);

        $panel = $this->panelFor($request, (int) $validated['panel_id']);

        foreach ($validated['services'] as $service) {
            $imported = BotService::updateOrCreate(
                [
                    'tenant_id' => $panel->tenant_id,
                    'panel_id' => $panel->id,
                    'provider_service_id' => $service['provider_service_id'],
                ],
                [
                    'name' => $service['name'],
                    'platform' => $service['platform'],
                    'category' => $service['category'] ?? null,
                    'unit_label' => $service['category'] ?? 'Followers',
                    'cost_price' => $service['cost_price'] ?? null,
                    'my_price' => $service['my_price'],
                    'min_quantity' => $service['min_quantity'],
                    'max_quantity' => max($service['min_quantity'], $service['max_quantity']),
                    'status' => 'active',
                ],
            );

            $this->fillFeaturesFromName($imported);
        }

        $count = count($validated['services']);

        return $this->afterSave($request)
            ->with('status', $count === 1 ? '1 service imported.' : "{$count} services imported.");
    }

    /**
     * What the name already promises ("No Drop", "365 Days Refill") becomes the
     * service's drop and refill lines, so customers are told without the
     * reseller typing it again for every import.
     *
     * Only blanks are filled: anything the reseller has set stays as they set it
     * when a service is imported a second time.
     */
    private function fillFeaturesFromName(BotService $service): void
    {
        $guess = ServiceFeatures::guess($service->name);
        $fill = [];

        foreach (['drop_info', 'refill_info'] as $column) {
            if (blank($service->{$column}) && $guess[$column] !== null) {
                $fill[$column] = $guess[$column];
            }
        }

        if ($fill !== []) {
            $service->update($fill);
        }
    }

    /**
     * Panels are tenant-scoped, so this cannot reach someone else's — but
     * being explicit here keeps that obvious at the point it matters.
     */
    private function panelFor(Request $request, int $panelId): TenantPanel
    {
        $panel = TenantPanel::where('tenant_id', $request->user()->id)
            ->whereKey($panelId)
            ->first();

        if ($panel === null) {
            throw ValidationException::withMessages([
                'panel_id' => 'That panel is no longer connected.',
            ]);
        }

        return $panel;
    }

    /**
     * Where to go after services are imported.
     *
     * The same endpoint serves three screens: the setup wizard, which must
     * advance to its next step, and Settings and the Services page, where the
     * reseller is in the middle of something and must be left where they are.
     * Only the wizard advances; a request that came from anywhere else stays
     * put. (The Services page used to be sent to the wizard, which — with setup
     * finished — sent it on to the dashboard.) With no referrer there is
     * nothing to go back to, so it advances.
     */
    private function afterSave(Request $request): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer');

        if ($referer !== '' && ! str_contains($referer, '/onboarding')) {
            return back(fallback: route('services.index'));
        }

        return redirect()->route('onboarding');
    }
}
