<?php

namespace App\Services\Pos\Loyverse;

/**
 * The name of the Loyverse customer for a checked-in unit: "Room 301 - Ahmad bin Ali", or "Bed A1 - ..." for a dorm
 * bed. Loyverse allows 64 characters, so the guest name is cut to fit. The note ties the customer back to the booking.
 */
class LoyverseGuestLabel
{
    private const int MAX = 64;

    public static function name(string $place, bool $isBed, string $guestName): string
    {
        $prefix = ($isBed ? 'Bed ' : 'Room ').$place.' - ';

        return mb_substr($prefix.trim($guestName), 0, self::MAX);
    }

    public static function checkedOut(string $name): string
    {
        return mb_substr('[Checked Out] '.$name, 0, self::MAX);
    }

    public static function note(string $reference): string
    {
        return 'Estatell '.$reference;
    }
}
