<?php

namespace App\Services\Payments;

use App\Models\TenantPaymentGateway;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Maps a submitted credential form onto the two encrypted columns.
 *
 * Lives here rather than in a controller because two screens save gateways —
 * onboarding, where a reseller connects their first one, and the gateways page,
 * where they manage the rest — and the blank-means-keep rule below is the kind
 * of thing that must not drift between them.
 */
class GatewayCredentials
{
    /**
     * A field left blank on a gateway that is already connected means "leave it
     * as it is": the form never sends the stored secret back, so treating blank
     * as a deletion would wipe a key the reseller only meant to keep.
     *
     * Blank with nothing stored is a different thing — that would leave the
     * gateway connected but unusable, so it is rejected.
     *
     * @param  array<string, string|null>  $submitted
     * @return array<string, string>
     */
    public static function columns(
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

            // Left blank on purpose: the gateway needs nothing in this slot.
            if (($field['optional'] ?? false) === true) {
                continue;
            }

            if ($existing?->{$column} === null) {
                throw ValidationException::withMessages([
                    "credentials.{$field['name']}" => "{$field['label']} is required.",
                ]);
            }
        }

        return $columns;
    }
}
