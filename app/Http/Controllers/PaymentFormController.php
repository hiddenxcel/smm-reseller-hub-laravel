<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaytmClient;
use App\Services\Payments\PayuClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * The page a customer lands on from a payment link, which immediately submits
 * the order to a gateway that insists on a POSTed form (PayU).
 *
 * A chat can only carry a link, so the link points here. It is signed and
 * expires, the order inside it is encrypted, and the form will only ever post
 * to a gateway address on the allow-list — a signed link is still not a licence
 * to send a customer's browser anywhere.
 */
class PaymentFormController extends Controller
{
    private const ALLOWED_ACTIONS = PayuClient::PAYMENT_URLS;

    /** Addresses that carry a query string are allowed by their start. */
    private const ALLOWED_PREFIXES = PaytmClient::PAYMENT_PAGE_PREFIXES;

    public function __invoke(Request $request): View
    {
        abort_unless($request->hasValidSignature(), 403);

        try {
            $order = json_decode(Crypt::decryptString((string) $request->query('d')), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(404);
        }

        abort_unless(
            is_array($order)
            && $this->isAllowed($order['action'] ?? null)
            && is_array($order['fields'] ?? null),
            404,
        );

        return view('payment.form', [
            'action' => $order['action'],
            'fields' => $order['fields'],
        ]);
    }

    private function isAllowed(mixed $action): bool
    {
        if (! is_string($action)) {
            return false;
        }

        if (in_array($action, self::ALLOWED_ACTIONS, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
