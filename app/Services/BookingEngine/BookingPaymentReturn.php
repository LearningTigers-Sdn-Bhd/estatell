<?php

namespace App\Services\BookingEngine;

use App\Models\BookingHold;
use App\Models\GatewayPayment;

/**
 * Where a guest goes after Curlec's checkout: the return address the payment named, for an API only website, or the
 * microsite's page for the hold. The token is long and random, and the page reads the hold's status from the API.
 * See docs/29-booking-engine/04-payment.md#guest-return.
 */
class BookingPaymentReturn
{
    public function url(BookingHold $hold, ?GatewayPayment $payment, bool $isPaid): string
    {
        $status = $isPaid ? 'confirming' : 'failed';

        if ($payment?->return_url !== null) {
            $separator = str_contains($payment->return_url, '?') ? '&' : '?';

            return $payment->return_url.$separator.http_build_query(['hold' => $hold->token, 'payment' => $status]);
        }

        $base = rtrim((string) config('estatell.booking_site_url'), '/');

        return $base.'/'.$hold->property->slug.'/book/'.$hold->token.'?payment='.$status;
    }
}
