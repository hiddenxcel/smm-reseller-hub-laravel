<?php

namespace App\Services\Payments;

/**
 * Paytm's request signature, as its own checksum libraries compute it.
 *
 * A signature is `AES-128-CBC( sha256(data | salt) . salt )` under the
 * merchant key, where the salt is four random characters. Verifying decrypts
 * it, takes the last four characters as the salt, and recomputes.
 *
 * Two shapes of `data`: a JSON request body is signed as the exact string that
 * is sent; a callback's fields are signed as their values, sorted by name and
 * joined with "|".
 */
final class PaytmChecksum
{
    /** Fixed by Paytm: part of the scheme, not a secret. */
    private const IV = '@@@@&&&&####$$$$';

    private const SALT_LENGTH = 4;

    private const SALT_ALPHABET = '9876543210ZYXWVUTSRQPONMLKJIHGFEDCBAabcdefghijklmnopqrstuvwxyz!@#$&_';

    /** Sign a request body (the exact JSON string that will be sent) or a parameter array. */
    public static function generate(array|string $data, string $merchantKey): string
    {
        $string = is_array($data) ? self::joinValues($data) : $data;

        return self::encrypt(self::hash($string, self::salt()), $merchantKey);
    }

    /**
     * @param  array<string, mixed>|string  $data  a callback's fields (without CHECKSUMHASH) or a body string
     */
    public static function verify(array|string $data, string $merchantKey, string $checksum): bool
    {
        if ($checksum === '' || $merchantKey === '') {
            return false;
        }

        if (is_array($data)) {
            unset($data['CHECKSUMHASH']);
        }

        $string = is_array($data) ? self::joinValues($data) : $data;

        $decrypted = self::decrypt($checksum, $merchantKey);

        if ($decrypted === null || strlen($decrypted) <= self::SALT_LENGTH) {
            return false;
        }

        $salt = substr($decrypted, -self::SALT_LENGTH);

        return hash_equals(self::hash($string, $salt), $decrypted);
    }

    /** sha256(data|salt) followed by the salt itself. */
    private static function hash(string $data, string $salt): string
    {
        return hash('sha256', $data.'|'.$salt).$salt;
    }

    private static function encrypt(string $input, string $key): string
    {
        return (string) openssl_encrypt($input, 'AES-128-CBC', $key, 0, self::IV);
    }

    private static function decrypt(string $encrypted, string $key): ?string
    {
        $out = openssl_decrypt($encrypted, 'AES-128-CBC', $key, 0, self::IV);

        return $out === false ? null : $out;
    }

    /** Values sorted by field name, null as empty, joined with "|". */
    private static function joinValues(array $params): string
    {
        ksort($params);

        return implode('|', array_map(
            fn ($value) => $value === null || $value === 'null' ? '' : (string) (is_scalar($value) ? $value : json_encode($value)),
            $params,
        ));
    }

    private static function salt(): string
    {
        $salt = '';
        $max = strlen(self::SALT_ALPHABET) - 1;

        for ($i = 0; $i < self::SALT_LENGTH; $i++) {
            $salt .= self::SALT_ALPHABET[random_int(0, $max)];
        }

        return $salt;
    }
}
