<?php

namespace App\Services\Panel;

use App\Models\BotService;
use App\Models\Tenant;
use App\Models\TenantPanel;
use App\Services\Payments\PricingCurrency;
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

    /**
     * Panels can return thousands, and a picker with more than this is
     * unusable — but a limit too low hides services the reseller is looking
     * for: at 300, a panel of 465 showed only its first 300 and a search for
     * anything past them found nothing.
     */
    private const MAX_SERVICES = 2000;

    /**
     * What the Services page's own importer asks for: a reseller there searches
     * the whole panel, and a panel of 9,000 must not hide the 7,000 after the
     * first 2,000. That screen draws only a few hundred matches at a time, so
     * the size of the list costs it a download, not a slow page.
     */
    public const WHOLE_PANEL = 50000;

    public function forPanel(TenantPanel $panel, int $limit = self::MAX_SERVICES): CatalogueResult
    {
        $response = SmmProviderClient::forPanel($panel)->getServices();

        if ($response->failed) {
            return CatalogueResult::failed($response->message ?? 'Could not read that panel.');
        }

        $tenant = Tenant::find($panel->tenant_id);

        $alreadyImported = BotService::withoutTenantScope()
            ->where('tenant_id', $panel->tenant_id)
            ->where('panel_id', $panel->id)
            ->pluck('provider_service_id')
            ->all();

        $all = $response->get('services', []);

        $services = (new Collection($all))
            ->take($limit)
            ->map(fn (array $service) => $this->normalise($service, $alreadyImported, $panel, $tenant))
            ->filter()
            ->values();

        return CatalogueResult::loaded($services->all(), count($all));
    }

    /**
     * Turn one panel entry into something we can show and import. Returns
     * null for entries missing what an order needs.
     */
    private function normalise(array $service, array $alreadyImported, TenantPanel $panel, ?Tenant $tenant): ?array
    {
        $id = (string) ($service['service'] ?? '');
        $name = trim((string) ($service['name'] ?? ''));

        if ($id === '' || $name === '') {
            return null;
        }

        [$platform, $remainder] = $this->splitPlatform($name);
        $cost = (string) ($service['rate'] ?? '0');

        // The panel's currency, brought to the shop's: the price suggested and
        // the cost saved must be in the currency the shop sells in.
        if ($tenant !== null && is_numeric($cost)) {
            $cost = PricingCurrency::costFromPanel($cost, $panel->balance_currency, $tenant) ?? $cost;
        }

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

    /**
     * Followers/likes/views and friends, taken from what is left of the name.
     *
     * Matched as a singular stem so "Reaction" (seen on panels selling each
     * emoji as its own service: "Facebook Post Reaction [ 😂 ]") still hits
     * "Reactions" — a plural-only list had been quietly leaving every one of
     * those uncategorised, since "reaction" does not contain "reactions".
     */
    private function guessCategory(string $remainder): ?string
    {
        // "reaction" first: "Facebook Post Reaction | ... | Like 👍" names each
        // emoji after itself, and the service is a reaction either way — it
        // must not be sorted into "Likes" just because one emoji is a thumbs-up.
        $known = [
            'reaction', 'follower', 'like', 'view', 'subscriber', 'comment', 'share',
            'save', 'play', 'member', 'mention', 'retweet',
            'connection', 'upvote', 'listener', 'rating', 'review', 'vote', 'join',
        ];
        $haystack = mb_strtolower($remainder);

        // Not a countable noun — never pluralised, so it is matched on its own.
        if (str_contains($haystack, 'watch time')) {
            return 'Watch Time';
        }

        foreach ($known as $category) {
            if (str_contains($haystack, $category)) {
                return Str::title(Str::plural($category));
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
