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
            'Could not connect. Check the panel URL and your admin API key.'
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
}
