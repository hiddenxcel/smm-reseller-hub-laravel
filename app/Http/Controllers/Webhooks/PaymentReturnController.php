<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a hosted checkout sends the customer's browser back (PayU's surl and
 * furl).
 *
 * The response is handled exactly like a server notification — verified by its
 * signature, credited at most once — and then the customer is moved on to a
 * thank-you page. Arriving here proves nothing by itself: a visit without a
 * valid signed response credits nothing.
 */
class PaymentReturnController extends Controller
{
    public function __construct(private PaymentWebhookController $webhook) {}

    public function __invoke(Request $request, string $gateway): RedirectResponse
    {
        ($this->webhook)($request, $gateway);

        // 303 so the browser follows with a GET, not another POST.
        return redirect()->route('payment.thanks', status: 303);
    }
}
