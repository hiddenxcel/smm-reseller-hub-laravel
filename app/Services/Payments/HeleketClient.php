<?php

namespace App\Services\Payments;

/**
 * Heleket runs Cryptomus's API on its own host — same request shape, same
 * signature scheme, same webhook. Only the endpoint differs, so the whole
 * client is that one constant.
 */
class HeleketClient extends CryptomusClient
{
    protected const INVOICE_URL = 'https://api.heleket.com/v1/payment';
}
