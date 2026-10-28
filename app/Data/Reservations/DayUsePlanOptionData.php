<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

class DayUsePlanOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $price,
        public ?string $disabledReason,
    ) {}
}
