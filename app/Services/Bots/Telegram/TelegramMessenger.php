<?php

namespace App\Services\Bots\Telegram;

use App\Models\BotMessage;
use App\Models\ResponseTemplate;
use App\Services\Bots\BotLang;
use App\Services\Bots\BotMessenger;
use App\Services\Bots\WhatsAppLimits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends over the Telegram Bot API from the reseller's own bot.
 *
 * Lists and reply buttons both become inline keyboards: a tap comes back as a
 * callback with the same id the WhatsApp row or button would have carried, so
 * the handlers cannot tell the channels apart.
 */
class TelegramMessenger implements BotMessenger
{
    public function __construct(
        private TelegramApi $api,
        private int $tenantId,
        private string $botType = 'order',
        private string $lang = BotLang::DEFAULT,
    ) {}

    public function sendText(string $to, string $message, ?string $templateKey = null): bool
    {
        $message = WhatsAppLimits::fit($this->applyTemplate($templateKey, $message), TelegramLimits::TEXT);

        $sent = $this->sendMessage($to, $message);

        $this->logOutbound($to, $message, $templateKey);

        return $sent;
    }

    /** @param array<int, array{id: string, title: string, description?: string}> $rows */
    public function sendList(
        string $to,
        string $bodyText,
        string $buttonText,
        string $sectionTitle,
        array $rows,
        ?string $templateKey = null,
    ): bool {
        // One button per row, full width: the description has no place on a
        // button, so it goes into the title after a dash.
        $keyboard = array_map(fn (array $row) => [[
            'text' => WhatsAppLimits::fit(
                $row['title'].(($row['description'] ?? '') !== '' ? ' — '.$row['description'] : ''),
                TelegramLimits::BUTTON_TITLE,
            ),
            'callback_data' => self::callbackData($row['id']),
        ]], $rows);

        $sent = $this->sendMessage($to, WhatsAppLimits::fit($bodyText, TelegramLimits::TEXT), $keyboard);

        $this->logOutbound($to, "[list] {$bodyText}", $templateKey);

        return $sent;
    }

    /** @param array<int, array{id: string, title: string}> $buttons */
    public function sendButtons(string $to, string $bodyText, array $buttons, ?string $templateKey = null): bool
    {
        // Two to a row reads well on a phone; three short ones still fit.
        $cells = array_map(fn (array $button) => [
            'text' => WhatsAppLimits::fit($button['title'], TelegramLimits::BUTTON_TITLE),
            'callback_data' => self::callbackData($button['id']),
        ], $buttons);

        $keyboard = array_chunk($cells, count($cells) === 3 ? 3 : 2);

        $sent = $this->sendMessage($to, WhatsAppLimits::fit($bodyText, TelegramLimits::TEXT), $keyboard);

        $this->logOutbound($to, "[buttons] {$bodyText}", $templateKey);

        return $sent;
    }

    public function sendImage(string $to, string $imageUrl, string $caption, ?string $templateKey = null): bool
    {
        $caption = $this->applyTemplate($templateKey, $caption);
        $chatId = TelegramChat::chatId($to);

        if ($chatId === null) {
            return false;
        }

        $fitted = WhatsAppLimits::fit($caption, TelegramLimits::CAPTION);

        $response = $this->api->call('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $imageUrl,
            'caption' => TelegramFormat::toHtml($fitted),
            'parse_mode' => 'HTML',
        ]);

        $sent = $this->succeeded($response, $to);

        // A picture Telegram cannot fetch should not cost the customer the words.
        if (! $sent && ! $this->isBlocked($response)) {
            $sent = $this->sendMessage($to, WhatsAppLimits::fit($caption, TelegramLimits::TEXT));
        }

        $this->logOutbound($to, "[image] {$caption}", $templateKey);

        return $sent;
    }

    /**
     * The id is "chat_id:message_id" (see InboundMessage::fromTelegramUpdate);
     * only the chat is needed to show "typing…".
     */
    public function markReadWithTyping(string $messageId): bool
    {
        $chatId = explode(':', $messageId)[0] ?? '';

        if ($chatId === '') {
            return false;
        }

        return ($this->api->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing'])['ok'] ?? false) === true;
    }

    /**
     * An id that fits Telegram's 64-byte callback_data goes as it is; a longer
     * one (a category with a long name) is swapped for a short token and
     * remembered, and resolveCallback() undoes it when the tap comes back.
     */
    public static function callbackData(string $id): string
    {
        if (strlen($id) <= TelegramLimits::CALLBACK_BYTES) {
            return $id;
        }

        $token = '~'.substr(md5($id), 0, 16);
        Cache::put('tg-cb:'.$token, $id, now()->addDays(3));

        return $token;
    }

    public static function resolveCallback(string $data): string
    {
        return str_starts_with($data, '~') ? (string) Cache::get('tg-cb:'.$data, '') : $data;
    }

    /** @param array<int, array<int, array<string, string>>>|null $keyboard */
    private function sendMessage(string $to, string $text, ?array $keyboard = null): bool
    {
        $chatId = TelegramChat::chatId($to);

        if ($chatId === null) {
            return false;
        }

        $params = ['chat_id' => $chatId, 'link_preview_options' => ['is_disabled' => true]];

        if ($keyboard !== null) {
            $params['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $response = $this->api->call('sendMessage', $params + [
            'text' => TelegramFormat::toHtml($text),
            'parse_mode' => 'HTML',
        ]);

        // Text Telegram cannot parse is sent plain rather than lost.
        if (($response['ok'] ?? false) !== true
            && ! $this->isBlocked($response)
            && str_contains((string) ($response['description'] ?? ''), "can't parse")) {
            $response = $this->api->call('sendMessage', $params + ['text' => TelegramFormat::toPlain($text)]);
        }

        return $this->succeeded($response, $to);
    }

    /** @param array<string, mixed>|null $response */
    private function succeeded(?array $response, string $to): bool
    {
        if (($response['ok'] ?? false) === true) {
            return true;
        }

        if ($response !== null) {
            Log::warning('Telegram send failed', [
                'tenant_id' => $this->tenantId,
                'to' => $to,
                'code' => $response['error_code'] ?? null,
                'description' => mb_substr((string) ($response['description'] ?? ''), 0, 200),
            ]);
        }

        return false;
    }

    /** 403: the person blocked the bot or never started it. Nothing will get through. */
    private function isBlocked(?array $response): bool
    {
        return ($response['error_code'] ?? null) === 403;
    }

    private function applyTemplate(?string $templateKey, string $fallback): string
    {
        if ($templateKey === null) {
            return $fallback;
        }

        $override = ResponseTemplate::resolve($this->tenantId, $templateKey, $this->lang);

        return blank($override) ? $fallback : $override;
    }

    private function logOutbound(string $to, string $message, ?string $templateKey): void
    {
        try {
            BotMessage::withoutTenantScope()->create([
                'tenant_id' => $this->tenantId,
                'customer_phone' => $to,
                'direction' => 'out',
                'message' => $message,
                'template_key' => $templateKey,
                'bot_type' => $this->botType,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not log outbound bot message', ['error' => $e->getMessage()]);
        }
    }
}
