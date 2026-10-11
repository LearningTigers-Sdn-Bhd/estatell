<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One room's part of the unit folio balance in the Check out form, sent only when the folio also holds another room's
 * charges. `own` marks the unit being checked out, whose part also takes the payments. `amount` is read only text.
 */
class CheckOutRoomShareData extends Data
{
    public function __construct(
        public string $name,
        public string $amount,
        public bool $own,
    ) {}
}
