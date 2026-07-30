<?php

namespace App\Services\Panel;

/**
 * What we learned by probing a panel: enough to store a working connection,
 * plus the balance and service count as proof to show the reseller that it
 * really connected.
 */
final readonly class PanelDetection
{
    private function __construct(
        public bool $connected,
        public ?string $apiUrl,
        public ?string $authMethod,
        public ?string $balance,
        public ?string $currency,
        public ?int $servicesCount,
        public ?string $message,
    ) {}

    public static function found(
        string $apiUrl,
        string $authMethod,
        string $balance,
        ?string $currency,
        ?int $servicesCount,
    ): self {
        return new self(
            connected: true,
            apiUrl: $apiUrl,
            authMethod: $authMethod,
            balance: $balance,
            currency: $currency,
            servicesCount: $servicesCount,
            message: null,
        );
    }

    public static function failed(string $message): self
    {
        return new self(
            connected: false,
            apiUrl: null,
            authMethod: null,
            balance: null,
            currency: null,
            servicesCount: null,
            message: $message,
        );
    }

    /**
     * PerfectPanel-style builds are the ones that read the key from a header,
     * which is a good enough guess to prefill the type.
     */
    public function panelType(): string
    {
        return $this->authMethod === 'header' ? 'perfectpanel' : 'custom';
    }
}
