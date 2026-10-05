<?php

namespace App\Services\Panel;

/**
 * Works out how to talk to a reseller's panel from just a URL and an API key.
 *
 * Panels vary in two ways that a reseller should not have to know about: the
 * endpoint may or may not already include /api/v2, and the key goes either in
 * the body or in a header. Rather than asking, we normalise the URL and probe
 * a balance call until one combination answers.
 */
class PanelDetector
{
    /** Body-param auth is far more common, so it is tried first. */
    private const AUTH_METHODS = ['param', 'header'];

    public function detect(string $url, string $apiKey): PanelDetection
    {
        $endpoint = self::normaliseUrl($url);

        foreach (self::AUTH_METHODS as $authMethod) {
            $client = new SmmProviderClient($endpoint, $apiKey, $authMethod);
            $balance = $client->checkBalance();

            // A balance we can actually read is the signal that this
            // combination works — a 200 alone is not enough.
            if ($balance->failed || $balance->get('balance') === null) {
                continue;
            }

            $services = $client->getServices();

            return PanelDetection::found(
                apiUrl: $endpoint,
                authMethod: $authMethod,
                balance: (string) $balance->get('balance'),
                currency: $balance->get('currency'),
                servicesCount: $services->failed ? null : count($services->get('services', [])),
            );
        }

        return PanelDetection::failed(
            'Could not connect. Check the panel URL and your API key.'
        );
    }

    /**
     * Turn whatever the reseller pasted into an API endpoint: a bare domain
     * gets /api/v2 appended, while a URL already pointing at the API is left
     * alone.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $url = rtrim($url, '/');

        if (preg_match('#/api(/v\d+)?$#i', $url)) {
            return $url;
        }

        return $url.'/api/v2';
    }

    /**
     * A display name derived from the address, so the reseller is not asked
     * for one during setup.
     *
     * The host carries everything useful: `www.` and the TLD say nothing about
     * which panel this is, so they come off, and what remains is title-cased.
     * `panel.example.com` becomes "Panel Example". A name is only a label in
     * the dashboard — the reseller can rename it later in Settings.
     */
    public static function nameFromUrl(string $url): string
    {
        $host = parse_url(self::normaliseUrl($url), PHP_URL_HOST) ?: $url;

        $host = preg_replace('#^www\.#i', '', $host);

        // Drop the public suffix. Two labels are removed for a host like
        // `panel.co.uk` and one for `panel.com`, leaving the meaningful part.
        $labels = explode('.', $host);

        if (count($labels) > 2 && in_array($labels[count($labels) - 2], ['co', 'com', 'org', 'net', 'ac', 'gov'], true)) {
            $labels = array_slice($labels, 0, -2);
        } elseif (count($labels) > 1) {
            $labels = array_slice($labels, 0, -1);
        }

        $name = ucwords(str_replace(['-', '_', '.'], ' ', implode(' ', $labels)));

        return $name === '' ? 'My panel' : mb_substr($name, 0, 150);
    }
}
