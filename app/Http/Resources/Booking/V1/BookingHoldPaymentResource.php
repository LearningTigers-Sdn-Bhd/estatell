<?php

namespace App\Http\Resources\Booking\V1;

use App\Models\GatewayPayment;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a client needs to open Curlec's checkout. `amount` is in the smallest currency unit, as the checkout expects, and
 * `timeout` is the seconds left on the hold. The key ID is public. The secret never leaves estatell.
 *
 * @property GatewayPayment $resource
 */
class BookingHoldPaymentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;
        $hold = $payment->hold;
        $currency = $payment->property->settings->currency->getCurrencyCode();

        return [
            'key_id' => (string) config('services.curlec.key_id'),
            'order_id' => $payment->order_id,
            'amount' => Money::of($payment->amount, $currency)->getMinorAmount()->toInt(),
            'currency' => $currency,
            'callback_url' => route('webhooks.curlec.return', ['token' => $hold->token]),
            'timeout' => max(1, $hold->expires_at->getTimestamp() - now()->getTimestamp()),
        ];
    }
}
