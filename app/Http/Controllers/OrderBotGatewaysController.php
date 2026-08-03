<?php

namespace App\Http\Controllers;

use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\GatewayFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
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
            'gateways' => $this->gateways($connected),
        ]);
    }

    /**
     * Every gateway, with what the reseller has stored against it.
     *
     * Secrets never travel — only whether each field has something saved, so
     * the form can say "leave blank to keep" instead of showing a key back.
     *
     * @param  \Illuminate\Support\Collection<string, TenantPaymentGateway>  $connected
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

        if (! method_exists($client, 'registerIpn')) {
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
