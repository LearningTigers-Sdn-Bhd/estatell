<?php

namespace App\Services\Reservations;

use App\Models\Property;
use App\Models\Reservation;
use Random\Randomizer;
use RuntimeException;

/**
 * Picks an unused short booking code for a property: 6 random characters, without the look-alikes I, L, O, 0 and 1. It is
 * not a secret on its own, because it is printed on emails. See docs/12-reservations/01-reservation-shape.md.
 */
class ReservationShortCode
{
    public const string CHARACTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const int LENGTH = 6;

    public const int ATTEMPTS = 5;

    public function __construct(private Randomizer $randomizer = new Randomizer) {}

    public function generate(Property $property): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $code = $this->randomizer->getBytesFromString(self::CHARACTERS, self::LENGTH);

            if (! Reservation::query()->where('property_id', $property->id)->where('short_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('No unused reservation short code after '.self::ATTEMPTS.' attempts.');
    }
}
