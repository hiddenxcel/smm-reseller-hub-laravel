<?php

namespace App\Services\Panel;

use App\Models\TenantPanel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter for the standard SMM API v2, which nearly every reseller panel
 * speaks. Two auth styles are in the wild:
 *   - 'param'  : key in the POST body as `key` (most panels)
 *   - 'header' : key as a header (some PerfectPanel builds). Both X-Api-Key
 *                and Authorization are sent because panels disagree on which
 *                they read, and sending both is what the old client did.
 *
 * The API is form-encoded POST, not JSON, and signals errors with an `error`
 * key rather than an HTTP status — so a 200 does not mean success.
 */
class SmmProviderClient
{
    public function __construct(
        private string $apiUrl,
        private string $apiKey,
        private string $authMethod = 'param',
    ) {
        $this->apiUrl = rtrim($this->apiUrl, '/');
    }

    public static function forPanel(TenantPanel $panel): self
    {
        return new self(
            apiUrl: $panel->api_url,
            apiKey: $panel->api_key_enc ?? '',
            authMethod: $panel->auth_method ?? 'param',
        );
    }

    public function checkBalance(): PanelResponse
    {
        $result = $this->call(['action' => 'balance']);

        return $result->failed ? $result : PanelResponse::ok([
            'balance' => $result->data['balance'] ?? null,
            'currency' => $result->data['currency'] ?? null,
        ]);
    }

    public function getServices(): PanelResponse
    {
        $result = $this->call(['action' => 'services']);

        // A services listing is a bare array, not a keyed object.
        return $result->failed ? $result : PanelResponse::ok(['services' => $result->data]);
    }

    public function addOrder(string $serviceId, string $link, int $quantity): PanelResponse
    {
        $result = $this->call([
            'action' => 'add',
            'service' => $serviceId,
            'link' => $link,
            'quantity' => $quantity,
        ]);

        if ($result->failed) {
            return $result;
        }

        if (! isset($result->data['order'])) {
            return PanelResponse::error('Provider returned no order ID');
        }

        return PanelResponse::ok(['order_id' => (string) $result->data['order']]);
    }

    public function checkStatus(string $orderId): PanelResponse
    {
        $result = $this->call(['action' => 'status', 'order' => $orderId]);

        return $result->failed ? $result : PanelResponse::ok([
            'status' => $result->data['status'] ?? null,
            'start_count' => $result->data['start_count'] ?? null,
            'remains' => $result->data['remains'] ?? null,
        ]);
    }

    public function refill(string $orderId): PanelResponse
    {
        $result = $this->call(['action' => 'refill', 'order' => $orderId]);

        return $result->failed ? $result : PanelResponse::ok([
            'refill' => $result->data['refill'] ?? null,
        ]);
    }

    /**
     * Ask the panel to cancel an order.
     *
     * The API takes a comma-separated `orders` list and answers with one entry
     * per id, so a single cancel still comes back wrapped in an array. Most
     * panels only honour this while an order is still pending; a refusal comes
     * back as `{"cancel": {"error": "..."}}` with a 200, which is why the
     * per-entry error is unwrapped here rather than trusted as success.
     */
    public function cancel(string $orderId): PanelResponse
    {
        $result = $this->call(['action' => 'cancel', 'orders' => $orderId]);

        if ($result->failed) {
            return $result;
        }

        $entry = $result->data[0] ?? $result->data;

        if (is_array($entry) && isset($entry['cancel']) && is_array($entry['cancel'])) {
            $entry = $entry['cancel'];
        }

        if (is_array($entry) && isset($entry['error'])) {
            return PanelResponse::error((string) $entry['error']);
        }

        return PanelResponse::ok(['cancel' => is_array($entry) ? ($entry['cancel'] ?? null) : $entry]);
    }

    private function call(array $payload): PanelResponse
    {
        $request = Http::asForm()
            ->timeout(30)
            ->connectTimeout(10);

        if ($this->authMethod === 'header') {
            $request = $request->withHeaders([
                'X-Api-Key' => $this->apiKey,
                'Authorization' => 'Bearer '.$this->apiKey,
            ]);
        } else {
            $payload['key'] = $this->apiKey;
        }

        try {
            $response = $request->post($this->apiUrl, $payload);
        } catch (ConnectionException $e) {
            Log::warning('SMM panel unreachable', ['url' => $this->apiUrl, 'error' => $e->getMessage()]);

            return PanelResponse::error('Could not reach the panel');
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            Log::warning('SMM panel returned a non-JSON body', [
                'url' => $this->apiUrl,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 200),
            ]);

            return PanelResponse::error('Provider error');
        }

        // The API reports failures in the body, so this check matters more
        // than the HTTP status.
        if (isset($decoded['error'])) {
            return PanelResponse::error((string) $decoded['error']);
        }

        return PanelResponse::ok($decoded);
    }
}
