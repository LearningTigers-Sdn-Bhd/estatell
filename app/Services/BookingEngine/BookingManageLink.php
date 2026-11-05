<?php

namespace App\Services\BookingEngine;

use App\Models\BookingHold;

/**
 * The guest's Manage booking page on the microsite. It uses the hold's token, which is long and random, so a booking needs
 * no second token. The page reads the booking from the API.
 */
class BookingManageLink
{
    public function url(BookingHold $hold): string
    {
        $base = rtrim((string) config('estatell.booking_site_url'), '/');

        return $base.'/'.$hold->property->slug.'/manage/'.$hold->token;
    }
}
