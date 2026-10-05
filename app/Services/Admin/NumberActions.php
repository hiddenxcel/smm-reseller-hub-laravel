<?php

namespace App\Services\Admin;

use App\Models\NumberRental;
use App\Models\PlatformNumber;
use App\Models\Tenant;
use App\Models\TenantWhatsApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The pool of numbers resellers can rent, as the console manages it.
 *
 * Three rules shape everything here.
 *
 * **A rented number is not edited out from under its renter.** When it is
 * rented, its token is copied into the reseller's own bot setup, so changing
 * the token here has to change it there too — and changing which number it
 * *is* would silently repoint their bot at somebody else's, so that is refused
 * until it has been released.
 *
 * **Nothing with history is deleted.** A rental row points at the number, and
 * the message log points at the rental. A number that has ever been rented is
 * suspended instead, which takes it out of the pool and leaves the record.
 *
 * **The token is never shown.** It is write-only from the console: saved
 * encrypted, replaceable, and reported back only as "saved" and its last four
 * characters.
 */
class NumberActions
{
    private const GRAPH = 'https://graph.facebook.com/v22.0';

    /** The currency resellers are billed in; a number's price is quoted in it. */
    public static function currency(): string
    {
        return (string) config('billing.currency', 'USD');
    }

    /** "+255 704 984 690", "255704984690" and " +255-704-984690 " are one number. */
    public static function normalise(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return $digits === '' ? '' : '+'.$digits;
    }

    /**
     * @param  array<string, mixed>  $attributes  display_number, phone_number_id,
     *                                            token, waba_id, country, country_code, price
     */
    public static function create(array $attributes): PlatformNumber
    {
        $number = PlatformNumber::create([
            'display_number' => self::normalise($attributes['display_number']),
            'phone_number_id' => trim($attributes['phone_number_id']),
            'cloud_api_token_enc' => $attributes['token'],
            'waba_id' => filled($attributes['waba_id'] ?? null) ? trim($attributes['waba_id']) : null,
            'country' => $attributes['country'] ?? null,
            'country_code' => $attributes['country_code'] ?? null,
            'currency' => self::currency(),
            'monthly_cost' => $attributes['price'],
            'status' => 'available',
        ]);

        AdminAudit::record('numbers.create', [
            'number_id' => $number->id,
            'number' => $number->display_number,
            'price' => (float) $number->monthly_cost,
        ]);

        return $number;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RuntimeException when the change would break a rental in progress
     */
    public static function update(PlatformNumber $number, array $attributes): void
    {
        $rented = $number->status === 'rented';
        $newId = trim($attributes['phone_number_id']);

        if ($rented && $newId !== $number->phone_number_id) {
            throw new RuntimeException(
                'This number is rented. Changing its Phone number ID would repoint the renter\'s bot at a different number — release it first.',
            );
        }

        $oldId = $number->phone_number_id;
        $before = $number->only(['display_number', 'phone_number_id', 'waba_id', 'country', 'monthly_cost']);

        $changes = [
            'display_number' => self::normalise($attributes['display_number']),
            'phone_number_id' => $newId,
            'waba_id' => filled($attributes['waba_id'] ?? null) ? trim($attributes['waba_id']) : null,
            'country' => $attributes['country'] ?? null,
            'country_code' => $attributes['country_code'] ?? null,
            'monthly_cost' => $attributes['price'],
        ];

        // Blank keeps the saved token. The form never has it to send back.
        $tokenChanged = filled($attributes['token'] ?? null);

        if ($tokenChanged) {
            $changes['cloud_api_token_enc'] = $attributes['token'];
        }

        DB::transaction(function () use ($number, $changes, $rented, $oldId, $tokenChanged) {
            $number->update($changes);

            // The renter holds a copy of the credentials; keep it in step so
            // their bot does not go on answering with a token that no longer
            // works.
            if ($rented) {
                $copy = [
                    'display_number' => $number->display_number,
                    'waba_id' => $number->waba_id,
                ];

                if ($tokenChanged) {
                    $copy['cloud_api_token_enc'] = $number->cloud_api_token_enc;
                }

                // Through the model, one row at a time, not a bulk update: a
                // bulk update skips the casts, and this column is encrypted —
                // the token would be stored in plain text and then fail to
                // decrypt the next time the bot tried to send.
                TenantWhatsApp::withoutTenantScope()
                    ->where('phone_number_id', $oldId)
                    ->where('source', 'rented')
                    ->get()
                    ->each(fn (TenantWhatsApp $row) => $row->update($copy));
            }
        });

        AdminAudit::record('numbers.update', [
            'number_id' => $number->id,
            'before' => $before,
            'after' => collect($changes)->except('cloud_api_token_enc')->all(),
            'token_replaced' => $tokenChanged,
            'renter_synced' => $rented,
        ]);
    }

    /** Take an unrented number out of the pool without losing it. */
    public static function suspend(PlatformNumber $number): void
    {
        if ($number->status === 'rented') {
            throw new RuntimeException('This number is rented. Release it first, then suspend it.');
        }

        $number->update(['status' => 'suspended']);

        AdminAudit::record('numbers.suspend', ['number_id' => $number->id, 'number' => $number->display_number]);
    }

    public static function restore(PlatformNumber $number): void
    {
        if ($number->status !== 'suspended') {
            return;
        }

        $number->update(['status' => 'available']);

        AdminAudit::record('numbers.restore', ['number_id' => $number->id, 'number' => $number->display_number]);
    }

    /**
     * Take a number back from whoever is renting it.
     *
     * The same thing a reseller does when they hand it back, done on their
     * behalf: the rental ends, the number returns to the pool, and their bot on
     * it stops. Their conversations, orders and customers stay theirs.
     *
     * @return array{tenant: string|null}
     */
    public static function release(PlatformNumber $number): array
    {
        $rental = NumberRental::withoutTenantScope()
            ->where('platform_number_id', $number->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        $tenant = $rental ? Tenant::find($rental->tenant_id) : null;

        DB::transaction(function () use ($number, $rental) {
            $rental?->update(['status' => 'revoked', 'ends_at' => now()]);

            TenantWhatsApp::withoutTenantScope()
                ->where('phone_number_id', $number->phone_number_id)
                ->where('source', 'rented')
                ->update(['status' => 'inactive']);

            $number->update(['status' => 'available']);
        });

        AdminAudit::record('numbers.release', [
            'number_id' => $number->id,
            'number' => $number->display_number,
            'tenant_id' => $tenant?->id,
            'tenant' => $tenant?->business_name,
        ]);

        return ['tenant' => $tenant?->business_name];
    }

    /**
     * Remove a number that was never rented.
     *
     * @throws RuntimeException if it has any rental history
     */
    public static function delete(PlatformNumber $number): void
    {
        if ($number->status === 'rented') {
            throw new RuntimeException('This number is rented. Release it first.');
        }

        $everRented = NumberRental::withoutTenantScope()
            ->where('platform_number_id', $number->id)
            ->exists();

        if ($everRented) {
            throw new RuntimeException(
                'This number has been rented before, and that history points at it. Suspend it instead — it leaves the pool and the record stays.',
            );
        }

        AdminAudit::record('numbers.delete', ['number_id' => $number->id, 'number' => $number->display_number]);

        $number->delete();
    }

    /**
     * Ask Meta whether this number and token actually work together.
     *
     * Catching a wrong Phone number ID or a dead token here, in the form,
     * is the difference between an admin finding out now and a reseller finding
     * out after they have paid for a number that cannot send.
     *
     * @return array{ok: bool, display_number?: string, name?: string, quality?: string, status?: string, error?: string}
     */
    public static function verify(string $phoneNumberId, string $token): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get(self::GRAPH.'/'.rawurlencode(trim($phoneNumberId)), [
                    'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Number verification could not reach Meta', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Could not reach Meta. Check the server\'s connection and try again.'];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => (string) ($response->json('error.message') ?? 'Meta refused the request.'),
            ];
        }

        return [
            'ok' => true,
            'display_number' => (string) $response->json('display_phone_number'),
            'name' => (string) $response->json('verified_name'),
            'quality' => (string) $response->json('quality_rating'),
            'status' => (string) $response->json('code_verification_status'),
        ];
    }
}
