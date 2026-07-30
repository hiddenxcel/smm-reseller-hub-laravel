<?php

namespace App\Actions\Orders;

use App\Models\BotOrder;

final readonly class PlaceOrderResult
{
    private function __construct(
        public bool $placed,
        public ?BotOrder $order,
        public ?PlaceOrderFailure $failure,
    ) {}

    public static function placed(BotOrder $order): self
    {
        return new self(placed: true, order: $order, failure: null);
    }

    public static function failed(PlaceOrderFailure $failure): self
    {
        return new self(placed: false, order: null, failure: $failure);
    }
}
