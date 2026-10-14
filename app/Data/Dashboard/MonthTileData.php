<?php

namespace App\Data\Dashboard;

use App\Enums\Currency;
use Spatie\LaravelData\Data;

/**
 * The month tile: what was posted to folios and how many room nights are booked, for the month of the business date.
 */
class MonthTileData extends Data
{
    /**
     * @param  string  $month  Month name, for example "October".
     * @param  string  $from  First day of the month, `Y-m-d`.
     * @param  string  $to  The business date, `Y-m-d`.
     * @param  string  $posted  Revenue posted from the 1st to the business date, without tax and payments. Decimal string.
     * @param  int  $bookedNights  Room nights of live units with a stay date in the month.
     * @param  string  $bookedAmount  Room total of those nights. Decimal string.
     */
    public function __construct(
        public string $month,
        public string $from,
        public string $to,
        public ?Currency $currency,
        public string $posted,
        public int $bookedNights,
        public string $bookedAmount,
    ) {}
}
