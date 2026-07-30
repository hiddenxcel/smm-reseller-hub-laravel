<?php

namespace App\Actions\Orders;

/**
 * Why a PlaceOrder attempt did not result in an order. The bot turns this
 * into a customer-facing message; the webhook path mostly just logs it.
 */
enum PlaceOrderFailure: string
{
    /** The wallet did not hold enough to cover the order. */
    case InsufficientFunds = 'insufficient_funds';

    /** The debit itself was refused — a concurrent debit got there first. */
    case ChargeFailed = 'charge_failed';
}
