<?php

namespace App\Services\Panel;

use App\Models\BotService;
use App\Models\TenantPanel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads a panel's service list and prepares it for import.
 *
 * Panels return a flat list with names like "Instagram Followers | 30 Days
 * Refill" and a cost per 1000. Resellers need that split into a platform they
 * can browse by, and a price of their own with margin on top.
 */
class ServiceCatalogue
{
    /**
     * Platforms recognised as the leading word of a service name, mapped to
     * how the brand actually capitalises itself — "TikTok" and "YouTube"
     * are wrong under a plain title-case.
     */
    private const KNOWN_PLATFORMS = [
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'facebook' => 'Facebook',
        'twitter' => 'Twitter',
        'x' => 'X',
        'telegram' => 'Telegram',
        'spotify' => 'Spotify',
        'linkedin' => 'LinkedIn',
        'snapchat' => 'Snapchat',
        'threads' => 'Threads',
        'twitch' => 'Twitch',
        'soundcloud' => 'SoundCloud',
        'discord' => 'Discord',
        'pinterest' => 'Pinterest',
        'reddit' => 'Reddit',
        'whatsapp' => 'WhatsApp',
    ];

    /** What a service costs plus this much is what we suggest charging. */
    private const SUGGESTED_MARGIN = 1.30;

    /** Panels can return thousands; more than this is unusable in a picker. */
    private const MAX_SERVICES = 300;

    public function forPanel(TenantPanel $panel): CatalogueResult
    {
        $response = SmmProviderClient::forPanel($panel)->getServices();

        if ($response->failed) {
            return CatalogueResult::failed($response->message ?? 'Could not read that panel.');
        }

        $alreadyImported = BotService::withoutTenantScope()
            ->where('tenant_id', $panel->tenant_id)
            ->where('panel_id', $panel->id)
            ->pluck('provider_service_id')
            ->all();

        $services = (new Collection($response->get('services', [])))
            ->take(self::MAX_SERVICES)
            ->map(fn (array $service) => $this->normalise($service, $alreadyImported))
            ->filter()
            ->values();

        return CatalogueResult::loaded($services->all());
    }

    /**
     * Turn one panel entry into something we can show and import. Returns
     * null for entries missing what an order needs.
     */
    private function normalise(array $service, array $alreadyImported): ?array
    {
        $id = (string) ($service['service'] ?? '');
        $name = trim((string) ($service['name'] ?? ''));

        if ($id === '' || $name === '') {
            return null;
        }

        [$platform, $remainder] = $this->splitPlatform($name);
        $cost = (string) ($service['rate'] ?? '0');

        return [
            'provider_service_id' => $id,
            'name' => $name,
            'platform' => $platform,
            'category' => $this->guessCategory($remainder),
            'cost_price' => $cost,
            'suggested_price' => $this->suggestPrice($cost),
            'min_quantity' => max(1, (int) ($service['min'] ?? 1)),
            'max_quantity' => max(1, (int) ($service['max'] ?? 100000)),
            'imported' => in_array($id, $alreadyImported, true),
        ];
    }

    /**
     * Panel names nearly always lead with the platform. When the first word
     * is not one we know, the whole name becomes the platform rather than
     * guessing wrongly.
     *
     * @return array{0: string, 1: string}
     */
    private function splitPlatform(string $name): array
    {
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];
        $first = mb_strtolower(trim($parts[0], " \t|-"));

        if (count($parts) === 2 && isset(self::KNOWN_PLATFORMS[$first])) {
            return [self::KNOWN_PLATFORMS[$first], $parts[1]];
        }

        return ['Other', $name];
    }

    /** Followers/likes/views and friends, taken from what is left of the name. */
    private function guessCategory(string $remainder): ?string
    {
        $known = ['followers', 'likes', 'views', 'subscribers', 'comments', 'shares', 'saves', 'plays', 'members', 'reactions', 'watch time'];
        $haystack = mb_strtolower($remainder);

        foreach ($known as $category) {
            if (str_contains($haystack, $category)) {
                return Str::title($category);
            }
        }

        return null;
    }

    /**
     * A starting point, not a decision — the reseller edits it before
     * importing. Rounded to four places to match the column.
     */
    private function suggestPrice(string $cost): string
    {
        return bcmul($cost, (string) self::SUGGESTED_MARGIN, 4);
    }
}
