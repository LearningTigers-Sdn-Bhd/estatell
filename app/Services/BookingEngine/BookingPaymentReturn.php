<?php

namespace App\Services\BookingEngine;

use App\Models\BookingHold;

/**
 * Where a guest goes after Curlec's checkout: the microsite's page for the hold. The token is long and random, and the
 * page reads the hold's status from the API. API only return addresses come with API only mode.
 */
class BookingPaymentReturn
{
    public function url(BookingHold $hold, bool $isPaid): string
    {
        $base = rtrim((string) config('estatell.booking_site_url'), '/');

        return $base.'/'.$hold->property->slug.'/book/'.$hold->token.'?payment='.($isPaid ? 'confirming' : 'failed');
    }
}
