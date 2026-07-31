<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\TenantPaymentGateway;
use App\Services\Payments\Gateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Where a reseller plugs in the gateway their own customers pay through.
 *
 * Which gateways exist, and which credentials each one needs, comes from
 * config/gateways.php — adding a gateway there gives it a working form here
 * with no change to this class.
 */
class SetupPaymentsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gateway' => ['required', 'string', Rule::in(array_keys(Gateway::all()))],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);

        $gateway = $validated['gateway'];
        $existing = $this->existingFor($request->user()->id, $gateway);

        $columns = $this->credentialColumns($gateway, $validated['credentials'], $existing);

        TenantPaymentGateway::updateOrCreate(
            [
                'tenant_id' => $request->user()->id,
                'gateway' => $gateway,
            ],
            [
                ...$columns,
                'status' => 'active',
            ],
        );

        return redirect()
            ->route('onboarding')
            ->with('status', Gateway::label($gateway).' connected.');
    }

    /**
     * Disconnecting keeps the row but marks it inactive, so a reseller who
     * turns a gateway back on does not have to find their keys again.
     */
    public function destroy(Request $request, string $gateway): RedirectResponse
    {
        TenantPaymentGateway::where('tenant_id', $request->user()->id)
            ->where('gateway', $gateway)
            ->update(['status' => 'inactive']);

        return redirect()
            ->route('onboarding.step', 'payments')
            ->with('status', Gateway::label($gateway).' disconnected.');
    }

    /**
     * Map the submitted credentials onto the two encrypted columns.
     *
     * A field left blank on a gateway that is already connected means "leave
     * it as it is" — the form never sends back the stored secret, so treating
     * blank as a deletion would wipe a key the reseller only meant to keep.
     *
     * @param  array<string, string|null>  $submitted
     * @return array<string, string>
     */
    private function credentialColumns(
        string $gateway,
        array $submitted,
        ?TenantPaymentGateway $existing,
    ): array {
        $columns = [];

        foreach (Arr::get(Gateway::all(), "{$gateway}.fields", []) as $field) {
            $column = $field['store'].'_enc';
            $value = trim((string) ($submitted[$field['name']] ?? ''));

            if ($value !== '') {
                $columns[$column] = $value;

                continue;
            }

            // Nothing typed and nothing stored — the gateway would be
            // connected but unusable, so say so instead.
            if ($existing?->{$column} === null) {
                throw ValidationException::withMessages([
                    "credentials.{$field['name']}" => "{$field['label']} is required.",
                ]);
            }
        }

        return $columns;
    }

    private function existingFor(int $tenantId, string $gateway): ?TenantPaymentGateway
    {
        return TenantPaymentGateway::where('tenant_id', $tenantId)
            ->where('gateway', $gateway)
            ->first();
    }
}
