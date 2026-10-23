<?php

namespace App\Data\StayView;

use App\Data\Rates\RateCalendarCellData;
use Spatie\LaravelData\Data;

/**
 * The nightly price of a rate plan for a room type on one date. `amount` is null when the plan has no price that
 * night, and `closed` is true when the night is stop sell.
 */
class DateRateData extends Data
{
    public function __construct(
        public string $date,
        public ?string $amount,
        public bool $closed,
    ) {}

    public static function fromCell(RateCalendarCellData $cell): self
    {
        return new self($cell->date, $cell->amount, $cell->stopSell);
    }
}
