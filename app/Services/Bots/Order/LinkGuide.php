<?php

namespace App\Services\Bots\Order;

/**
 * What a customer is told when the bot asks for their link: which steps to
 * follow, an example of the link, and an illustration of the taps.
 *
 * Only the four platforms that have an illustration are known; anything else
 * gets generic steps and no image rather than a wrong picture.
 */
final class LinkGuide
{
    /** Orders for these are placed on a profile; everything else is a post or video. */
    private const PROFILE_TYPES = ['followers', 'subscribers', 'members'];

    private const EXAMPLES = [
        'instagram' => ['profile' => 'https://www.instagram.com/yourname', 'post' => 'https://www.instagram.com/p/AbC123xyz'],
        'tiktok' => ['profile' => 'https://www.tiktok.com/@yourname', 'post' => 'https://www.tiktok.com/@yourname/video/123456'],
        'facebook' => ['profile' => 'https://www.facebook.com/yourname', 'post' => 'https://www.facebook.com/share/p/AbC123'],
        'youtube' => ['profile' => 'https://www.youtube.com/@yourchannel', 'post' => 'https://www.youtube.com/watch?v=AbC123xyz'],
    ];

    /**
     * @param  ?string  $category  what the customer picked (Followers, Likes…); wins over the unit label
     *                             because a service's unit label is only a default
     * @return array{type: string, stepsKey: string, example: string, imageUrl: ?string}
     */
    public static function for(string $platform, ?string $category, string $unit): array
    {
        $platformKey = mb_strtolower(trim($platform));
        $type = in_array(mb_strtolower(trim($category ?: $unit)), self::PROFILE_TYPES, true) ? 'profile' : 'post';

        if (! isset(self::EXAMPLES[$platformKey])) {
            return [
                'type' => $type,
                'stepsKey' => 'link_steps_generic',
                'example' => 'https://example.com/yourname',
                'imageUrl' => null,
            ];
        }

        return [
            'type' => $type,
            'stepsKey' => "link_steps_{$platformKey}_{$type}",
            'example' => self::EXAMPLES[$platformKey][$type],
            'imageUrl' => rtrim((string) config('app.url'), '/')."/assets/instructions/{$platformKey}_{$type}.png",
        ];
    }
}
