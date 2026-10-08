<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Bots\BotRoute;
use App\Services\Bots\BotRouter;
use App\Services\Bots\InboundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The one webhook Meta calls for every reseller on the platform. Which
 * reseller a message belongs to is worked out from the phone_number_id
 * inside the payload — see BotRouter.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private BotRouter $router) {}

    /**
     * Meta's subscription handshake: echo the challenge back, but only to
     * someone who knows the verify token.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.meta.verify_token');
        $provided = (string) $request->query('hub_verify_token', '');

        $ok = $request->query('hub_mode') === 'subscribe'
            && $expected !== ''
            && hash_equals($expected, $provided);

        if (! $ok) {
            return response('verification failed', 403);
        }

        return response((string) $request->query('hub_challenge'))
            ->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): Response
    {
        if (! $this->signatureIsValid($request)) {
            return response('invalid signature', 401);
        }

        $message = InboundMessage::fromMetaPayload($request->json()->all());

        // Delivery and read receipts arrive on this same webhook with no
        // message in them; there is nothing to route.
        if ($message === null) {
            $this->noteUnreadableMessage($request->json()->all());

            return $this->ack(BotRoute::NoMessage->value);
        }

        try {
            $route = $this->router->route($message)->value;
        } catch (\Throwable $e) {
            // Meta retries anything that is not a 200, and a retry would
            // replay the whole conversation step. Better to swallow, log, and
            // let the customer resend.
            Log::error('WhatsApp webhook failed', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            $route = 'error';
        }

        return $this->ack($route);
    }

    /**
     * A payload that carries a message we still could not use — most likely a
     * sender Meta identified without a phone number — would otherwise vanish
     * as a "receipt". Say so in the log, with the shape of what arrived and
     * none of its content, so the cause can be seen rather than guessed.
     */
    private function noteUnreadableMessage(array $payload): void
    {
        $value = data_get($payload, 'entry.0.changes.0.value');
        $message = is_array($value) ? data_get($value, 'messages.0') : null;

        if (! is_array($message)) {
            return;
        }

        Log::warning('WhatsApp webhook had a message that could not be read', [
            'value_keys' => array_keys($value),
            'message_keys' => array_keys($message),
            'type' => $message['type'] ?? null,
            'has_from' => ($message['from'] ?? '') !== '',
            'has_phone_number_id' => data_get($value, 'metadata.phone_number_id') !== null,
            'contact_keys' => array_keys((array) data_get($value, 'contacts.0', [])),
            'contact_profile_keys' => array_keys((array) data_get($value, 'contacts.0.profile', [])),
        ]);
    }

    /**
     * Meta signs the raw body with the app secret. Without a configured
     * secret nothing can be verified, so the request is refused rather than
     * trusted — an unauthenticated caller here could drive any reseller's bot.
     */
    private function signatureIsValid(Request $request): bool
    {
        $appSecret = (string) config('services.meta.app_secret');

        if ($appSecret === '') {
            Log::warning('Rejected a WhatsApp webhook: META_APP_SECRET is not configured');

            return false;
        }

        $provided = (string) $request->header('X-Hub-Signature-256', '');

        if ($provided === '') {
            return false;
        }

        // Signed over the exact bytes received, so getContent() — not a
        // re-encoded array.
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $provided);
    }

    /** Meta only cares that it got a 200; the body is for our own logs. */
    private function ack(string $status): Response
    {
        return response($status)->header('Content-Type', 'text/plain');
    }
}
