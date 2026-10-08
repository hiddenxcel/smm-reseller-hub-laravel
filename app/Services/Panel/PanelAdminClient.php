<?php

namespace App\Services\Panel;

use App\Models\TenantPanel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A reseller's panel, spoken to as its administrator.
 *
 * The reseller API (SmmProviderClient) only sees the orders of the account that
 * owns the key, so it cannot answer for the panel's own customers. The panel's
 * Admin API can: find a customer, put a ticket in their account, read any
 * order, refill or cancel it. That power is why this client is used only after
 * a customer has proven the account is theirs, and only on orders that belong
 * to it — the panel's answer is shown as the panel gave it.
 *
 * The URL is the panel's address; `/api/admin` is added here.
 */
class PanelAdminClient
{
    public function __construct(
        private string $baseUrl,
        private string $key,
    ) {}

    /** Null when the panel has no Admin API set up. */
    public static function forPanel(?TenantPanel $panel): ?self
    {
        if ($panel === null || blank($panel->admin_api_url) || blank($panel->admin_api_key_enc)) {
            return null;
        }

        return new self(self::normalise((string) $panel->admin_api_url), (string) $panel->admin_api_key_enc);
    }

    /**
     * "https://panel.com", "https://panel.com/" or the full
     * "https://panel.com/api/admin" all mean the same panel.
     */
    public static function normalise(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/api/admin$#i', '', $url) ?? $url;
        $url = preg_replace('#/api(/v\d+)?$#i', '', $url) ?? $url;

        return $url.'/api/admin';
    }

    /** Does the key work? Cheap read-only call. */
    public function ping(): PanelResponse
    {
        return $this->call('get', '/stats');
    }

    /**
     * The customer with this username or email, or null when none matches.
     * Exact match only: a customer is a person, not a search result.
     */
    public function findUser(string $identifier): PanelResponse
    {
        $field = str_contains($identifier, '@') ? 'email' : 'username';

        $result = $this->call('get', '/users', [$field => $identifier, 'per_page' => 2]);

        if ($result->failed) {
            return $result;
        }

        $rows = $result->data['data'] ?? [];
        $user = is_array($rows) && count($rows) === 1 ? $rows[0] : null;

        return PanelResponse::ok([
            'user' => is_array($user) ? ['id' => (int) $user['id'], 'username' => (string) ($user['username'] ?? '')] : null,
        ]);
    }

    public function sendTicket(int $userId, string $subject, string $message): PanelResponse
    {
        return $this->call('post', "/users/{$userId}/tickets", ['subject' => $subject, 'message' => $message]);
    }

    /** One order, with its owner and the panel's own refill rule. */
    public function order(string $orderId): PanelResponse
    {
        if (preg_match('/^\d{1,18}$/', $orderId) !== 1) {
            return PanelResponse::error('Incorrect order ID', 404);
        }

        return $this->call('get', "/orders/{$orderId}");
    }

    public function refill(string $orderId): PanelResponse
    {
        return $this->call('post', "/orders/{$orderId}/refill");
    }

    public function cancel(string $orderId): PanelResponse
    {
        return $this->call('post', "/orders/{$orderId}/cancel");
    }

    private function call(string $method, string $path, array $data = []): PanelResponse
    {
        $request = Http::withToken($this->key)
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(10);

        try {
            $response = $method === 'get'
                ? $request->get($this->baseUrl.$path, $data)
                : $request->asJson()->post($this->baseUrl.$path, $data);
        } catch (ConnectionException $e) {
            Log::warning('Panel Admin API unreachable', ['url' => $this->baseUrl, 'error' => $e->getMessage()]);

            return PanelResponse::error('Could not reach the panel');
        }

        $body = $response->json();

        if (! is_array($body)) {
            Log::warning('Panel Admin API returned a non-JSON body', ['url' => $this->baseUrl, 'status' => $response->status()]);

            return PanelResponse::error('Provider error', $response->status());
        }

        if ($response->failed()) {
            return PanelResponse::error((string) ($body['error'] ?? $body['message'] ?? 'Provider error'), $response->status());
        }

        return PanelResponse::ok($body);
    }
}
