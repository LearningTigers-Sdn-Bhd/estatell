<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One line the check-out will post for the pending night, shown in the Check out form as added now. `level` is 0 for a
 * charge and 1 for its discount or tax. `amount` is read only text.
 */
class UnitPendingLineData extends Data
{
    public function __construct(
        public string $description,
        public string $amount,
        public int $level,
    ) {}
}
