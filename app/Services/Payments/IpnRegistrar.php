<?php

namespace App\Services\Payments;

/**
 * A gateway that will not accept an order until our notification URL has been
 * registered with it, and issues an id for it that every order must quote.
 *
 * Only Pesapal so far. It is an interface rather than a special case in the
 * controller because the id is obtainable no other way — a reseller cannot
 * find it in a dashboard — so the button that calls this is the only route to
 * a working Pesapal setup.
 */
interface IpnRegistrar
{
    /** @return string|null the id to quote on every order, or null if refused */
    public function registerIpn(string $url): ?string;
}
