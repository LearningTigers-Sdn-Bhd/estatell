<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\BookingHold;
use App\Models\GatewayPayment;
use App\Services\BookingEngine\BookingPaymentReturn;
use App\Services\Payments\CurlecGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurlecReturnController extends Controller
{
    /**
     * Send the guest back from Curlec's checkout to the page that shows the hold. It writes nothing, because only the
     * webhook creates a booking. A failed payment or a bad signature returns with `payment=failed`; the guest can retry
     * while the hold is open. See docs/29-booking-engine/04-payment.md#flow.
     */
    public function __invoke(Request $request, string $token, CurlecGateway $gateway, BookingPaymentReturn $paymentReturn): RedirectResponse
    {
        $hold = BookingHold::query()->with('property')->where('token', $token)->firstOrFail();
        $orderId = (string) $request->input('razorpay_order_id');
        $isOwnOrder = $orderId !== '' && GatewayPayment::query()->where('booking_hold_id', $hold->id)->where('order_id', $orderId)->exists();
        $isPaid = $isOwnOrder && $gateway->verifySignature($orderId, (string) $request->input('razorpay_payment_id'), (string) $request->input('razorpay_signature'));

        return redirect()->away($paymentReturn->url($hold, $isPaid), 303);
    }
}
