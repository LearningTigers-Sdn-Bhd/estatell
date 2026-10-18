<?php

namespace App\Data\Dashboard;

use App\Data\Reservations\BookingSourceBadgeData;
use App\Enums\BookingSourceType;
use Spatie\LaravelData\Data;

/**
 * One row of the channel mix: a booking source, or a source type in the Type view.
 */
class ChannelMixRowData extends Data
{
    /**
     * @param  string  $key  The source id, or the type value in the Type view.
     * @param  BookingSourceBadgeData|null  $badge  Null in the Type view.
     * @param  int  $share  Whole percent. The rows of a view add up to 100.
     * @param  string  $amount  Room total of the nights. Decimal string.
     * @param  list<ChannelMixCompanyData>  $companies  Only on the Company / Travel agent row, largest first.
     */
    public function __construct(
        public string $key,
        public string $name,
        public BookingSourceType $type,
        public ?BookingSourceBadgeData $badge,
        public int $nights,
        public int $share,
        public string $amount,
        public array $companies,
    ) {}
}
