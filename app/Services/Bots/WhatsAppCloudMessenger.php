<?php

namespace App\Services\Bots;

use App\Models\BotMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends over the WhatsApp Cloud API using the tenant's own number and token —
 * each reseller messages their customers from their own WhatsApp presence.
 *
 * Every outbound message is logged to bot_messages so the reseller's inbox
 * shows both sides of the conversation.
 */
class WhatsAppCloudMessenger implements BotMessenger
{
    private const GRAPH_VERSION = 'v22.0';

    /** Meta truncates past these; doing it here keeps ids and labels aligned. */
    private const LIST_TITLE_LIMIT = 24;

    private const LIST_DESCRIPTION_LIMIT = 72;

    private const BUTTON_TITLE_LIMIT = 20;

    public function __construct(
        private string $phoneNumberId,
        private string $token,
        private int $tenantId,
        private string $footer = '',
        private string $botType = 'order',
    ) {}

    public function sendText(string $to, string $message, ?string $templateKey = null): bool
    {
        $sent = $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message],
        ]);

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
        $sent = $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $bodyText],
                'footer' => ['text' => $this->footer],
                'action' => [
                    'button' => $buttonText,
                    'sections' => [[
                        'title' => $sectionTitle,
                        'rows' => array_map(fn (array $row) => [
                            'id' => $row['id'],
                            'title' => mb_substr($row['title'], 0, self::LIST_TITLE_LIMIT),
                            'description' => mb_substr($row['description'] ?? '', 0, self::LIST_DESCRIPTION_LIMIT),
                        ], $rows),
                    ]],
                ],
            ],
        ]);

        $this->logOutbound($to, "[list] {$bodyText}", $templateKey);

        return $sent;
    }

    /** @param array<int, array{id: string, title: string}> $buttons up to 3 */
    public function sendButtons(string $to, string $bodyText, array $buttons, ?string $templateKey = null): bool
    {
        $sent = $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'footer' => ['text' => $this->footer],
                'action' => [
                    'buttons' => array_map(fn (array $button) => [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $button['id'],
                            'title' => mb_substr($button['title'], 0, self::BUTTON_TITLE_LIMIT),
                        ],
                    ], $buttons),
                ],
            ],
        ]);

        $this->logOutbound($to, "[buttons] {$bodyText}", $templateKey);

        return $sent;
    }

    public function markReadWithTyping(string $messageId): bool
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
            'typing_indicator' => ['type' => 'text'],
        ]);
    }

    private function send(array $payload): bool
    {
        if ($this->token === '') {
            Log::warning('Skipping WhatsApp send: no token', ['tenant_id' => $this->tenantId]);

            return false;
        }

        $url = 'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->token)
                ->timeout(15)
                ->connectTimeout(5)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('WhatsApp Cloud API unreachable', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('WhatsApp send failed', [
                'tenant_id' => $this->tenantId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
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
            // Logging a message must never break the reply itself.
            Log::warning('Could not log outbound bot message', ['error' => $e->getMessage()]);
        }
    }
}
