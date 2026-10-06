<?php

namespace App\Http\Controllers;

use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayFamilies;
use App\Services\Payments\GatewayFactory;
use App\Services\Payments\IpnRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a reseller connects the gateway their own customers pay through.
 *
 * Distinct from billing, which is how the reseller pays the platform. Money
 * here flows customer -> reseller, and the credentials are the reseller's own
 * merchant account.
 *
 * Which gateways exist and what each needs comes from config/gateways.php, so
 * adding one there gives it a working form with no change to this class.
 */
class OrderBotGatewaysController extends Controller
{
    public function __construct(private GatewayFactory $factory) {}

    public function index(Request $request): Response
    {
        $tenantId = (int) $request->user()->id;

        $connected = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('gateway');

        return Inertia::render('OrderBot/Gateways', [
            // A family (FimiPay, Snippe) is one account with a switch per
            // market, so it is shown as one card and kept out of the
            // per-gateway list.
            'gateways' => array_values(array_filter(
                $this->gateways($connected),
                fn (array $gateway) => GatewayFamilies::familyOf($gateway['code']) === null,
            )),
            'families' => array_map(fn (string $family) => $this->family($family, $connected), GatewayFamilies::families()),
        ]);
    }

    /**
     * Every gateway, with what the reseller has stored against it.
     *
     * Secrets never travel — only whether each field has something saved, so
     * the form can say "leave blank to keep" instead of showing a key back.
     *
     * @param  Collection<string, TenantPaymentGateway>  $connected
     * @return array<int, array>
     */
    private function gateways($connected): array
    {
        return array_values(array_map(function (string $code) use ($connected) {
            $row = $connected->get($code);

            return [
                'code' => $code,
                'label' => Gateway::label($code),
                'type' => Arr::get(Gateway::all(), "{$code}.type"),
                // A gateway with no client cannot take a payment yet, however
                // complete its credentials look — the page has to say so.
                'ready' => Gateway::isReady($code),
                'needsPhone' => Gateway::needsPhone($code),
                'verify' => Gateway::isVerify($code),
                // Pesapal issues its IPN id from an API call, so the reseller
                // is given a button rather than a value to hunt for.
                'registersIpn' => $code === 'pesapal',
                // Where the provider must be told to send its notifications, for
                // the ones that take a single URL set in their own dashboard.
                'webhookUrl' => Arr::get(Gateway::all(), "{$code}.webhook_setup", false)
                    ? route('webhooks.payment', $code)
                    : null,
                'connected' => $row !== null,
                'status' => $row?->status,
                'isDefault' => (bool) $row?->is_default,
                'fields' => array_map(fn (array $field) => [
                    'name' => $field['name'],
                    'label' => $field['label'],
                    'saved' => $row?->{$field['store'].'_enc'} !== null,
                ], Arr::get(Gateway::all(), "{$code}.fields", [])),
            ];
        }, array_keys(Gateway::all())));
    }

    /**
     * A family as the reseller sees it: one pair of keys, and which markets are on.
     *
     * @param  Collection<string, TenantPaymentGateway>  $connected
     * @return array<string, mixed>
     */
    private function family(string $family, $connected): array
    {
        $definition = GatewayFamilies::FAMILIES[$family];
        $rows = collect(GatewayFamilies::codes($family))->map(fn (string $code) => $connected->get($code))->filter();

        return [
            'family' => $family,
            'label' => $definition['label'],
            'intro' => $definition['intro'],
            'keyLabel' => $definition['keyLabel'],
            'secretLabel' => $definition['secretLabel'],
            'secretRequired' => $definition['secretRequired'],
            'keySaved' => $rows->contains(fn (TenantPaymentGateway $row) => $row->api_key_enc !== null),
            'webhookSecretSaved' => $rows->contains(fn (TenantPaymentGateway $row) => $row->webhook_secret_enc !== null),
            'markets' => array_map(fn (string $code) => [
                'code' => $code,
                'label' => GatewayFamilies::marketLabel($code),
                'on' => $connected->get($code)?->status === 'active',
                'isDefault' => (bool) $connected->get($code)?->is_default,
            ], GatewayFamilies::codes($family)),
        ];
    }

    /**
     * Save a family's keys once and say which markets are switched on.
     *
     * Every market is a row of its own so the rest of the platform (checkout,
     * the default gateway, payments) needs no special case — but the keys are
     * the same on all of them, so they are written to all of them here and the
     * reseller never types them twice. A blank field keeps what is stored.
     */
    public function saveFamily(Request $request, string $family): RedirectResponse
    {
        abort_unless(GatewayFamilies::exists($family), 404);

        $definition = GatewayFamilies::FAMILIES[$family];
        $codes = GatewayFamilies::codes($family);

        $validated = $request->validate([
            'credentials' => ['required', 'array'],
            'credentials.api_key' => ['nullable', 'string', 'max:500'],
            'credentials.webhook_secret' => ['nullable', 'string', 'max:500'],
            'markets' => ['present', 'array'],
            'markets.*' => ['string', Rule::in($codes)],
        ]);

        $tenantId = (int) $request->user()->id;

        $rows = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('gateway', $codes)
            ->get();

        $apiKey = trim((string) ($validated['credentials']['api_key'] ?? ''))
            ?: $rows->first(fn (TenantPaymentGateway $row) => $row->api_key_enc !== null)?->api_key_enc;
        $webhookSecret = trim((string) ($validated['credentials']['webhook_secret'] ?? ''))
            ?: $rows->first(fn (TenantPaymentGateway $row) => $row->webhook_secret_enc !== null)?->webhook_secret_enc;

        $missing = [];

        if ($apiKey === null) {
            $missing['credentials.api_key'] = "{$definition['keyLabel']} is required.";
        }

        if ($definition['secretRequired'] && $webhookSecret === null) {
            $missing['credentials.webhook_secret'] = "{$definition['secretLabel']} is required.";
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        foreach ($codes as $code) {
            TenantPaymentGateway::updateOrCreate(
                ['tenant_id' => $tenantId, 'gateway' => $code],
                [
                    'api_key_enc' => $apiKey,
                    'webhook_secret_enc' => $webhookSecret,
                    'status' => in_array($code, $validated['markets'], true) ? 'active' : 'inactive',
                ],
            );
        }

        return back()->with('success', "{$definition['label']} saved.");
    }
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gateway' => ['required', 'string', Rule::in(array_keys(Gateway::all()))],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);

        $gateway = $validated['gateway'];
        $tenantId = (int) $request->user()->id;

        $existing = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('gateway', $gateway)
            ->first();

        $columns = GatewayCredentials::columns($gateway, $validated['credentials'], $existing);

        TenantPaymentGateway::updateOrCreate(
            ['tenant_id' => $tenantId, 'gateway' => $gateway],
            [...$columns, 'status' => 'active'],
        );

        return back()->with('success', Gateway::label($gateway).' saved.');
    }

    /**
     * Turn a connected gateway off, or back on.
     *
     * The row and its keys survive either way — a reseller pausing a gateway
     * for a week should not have to find their credentials again.
     */
    public function toggle(Request $request, string $gateway): RedirectResponse
    {
        abort_unless(Gateway::exists($gateway), 404);

        $row = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $request->user()->id)
            ->where('gateway', $gateway)
            ->firstOrFail();

        $row->update(['status' => $row->status === 'active' ? 'inactive' : 'active']);

        return back()->with(
            'success',
            Gateway::label($gateway).($row->status === 'active' ? ' is on.' : ' is off.'),
        );
    }

    /**
     * Choose the gateway customers are sent to.
     *
     * Only a gateway that can actually take a payment may hold this: marking a
     * paused or unwired one as the default would look like a working setup and
     * send every customer to nothing.
     */
    public function setDefault(Request $request, string $gateway): RedirectResponse
    {
        abort_unless(Gateway::exists($gateway), 404);

        $row = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $request->user()->id)
            ->where('gateway', $gateway)
            ->firstOrFail();

        if (! Gateway::isReady($gateway) || $row->status !== 'active') {
            return back()->with(
                'error',
                Gateway::label($gateway).' cannot take payments yet, so it cannot be the default.',
            );
        }

        $row->makeDefault();

        return back()->with('success', 'Customers will pay through '.Gateway::label($gateway).'.');
    }

    /**
     * Register our notification URL with Pesapal and keep the id it issues.
     *
     * Pesapal will not accept an order that does not quote one, and the id is
     * only obtainable through this call — so it is a button here rather than a
     * value the reseller is expected to find.
     */
    public function registerIpn(Request $request): RedirectResponse
    {
        $row = TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $request->user()->id)
            ->where('gateway', 'pesapal')
            ->firstOrFail();

        $client = $this->factory->make($row);

        if (! $client instanceof IpnRegistrar) {
            return back()->with('error', 'This gateway does not need an IPN.');
        }

        $ipnId = $client->registerIpn(route('webhooks.payment', 'pesapal'));

        if ($ipnId === null) {
            return back()->with(
                'error',
                'Pesapal did not accept the registration. Check the consumer key and secret.',
            );
        }

        $row->update(['extra_enc' => $ipnId]);

        return back()->with('success', 'Pesapal notifications are registered.');
    }

    /** Forget the credentials entirely, for a gateway a reseller has left. */
    public function destroy(Request $request, string $gateway): RedirectResponse
    {
        abort_unless(Gateway::exists($gateway), 404);

        TenantPaymentGateway::withoutTenantScope()
            ->where('tenant_id', $request->user()->id)
            ->where('gateway', $gateway)
            ->delete();

        return back()->with('success', Gateway::label($gateway).' removed.');
    }
}
