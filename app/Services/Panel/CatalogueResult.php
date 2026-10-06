<?php

namespace App\Services\Panel;

/**
 * A panel's catalogue as we managed to read it — either the services, or why
 * we could not.
 */
final readonly class CatalogueResult
{
    private function __construct(
        public bool $loaded,
        public array $services,
        public ?string $message,
        /** How many the panel has, which can be more than $services holds. */
        public int $total = 0,
    ) {}

    public static function loaded(array $services, ?int $total = null): self
    {
        return new self(loaded: true, services: $services, message: null, total: $total ?? count($services));
    }

    public static function failed(string $message): self
    {
        return new self(loaded: false, services: [], message: $message);
    }
}
