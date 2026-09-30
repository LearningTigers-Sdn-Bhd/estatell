<?php

namespace App\Data\Property;

use App\Enums\WeekendNights;
use Spatie\LaravelData\Data;

/** A weekend nights choice for the pricing form, with the ISO weekdays (1 = Monday) it covers. */
class WeekendNightsOptionData extends Data
{
    /**
     * @param  list<int>  $isoWeekdays
     */
    public function __construct(
        public string $value,
        public string $label,
        public array $isoWeekdays,
    ) {}

    public static function fromEnum(WeekendNights $weekendNights): self
    {
        return new self($weekendNights->value, $weekendNights->label(), $weekendNights->isoWeekdays());
    }
}
