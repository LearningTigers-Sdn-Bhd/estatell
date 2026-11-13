<?php

namespace App\Data\Reports;

use App\Data\Reservations\BookingSourceBadgeData;
use App\Data\Shared\BadgeData;
use App\Enums\ReportGroup;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden as HiddenFromTypeScript;

/**
 * One row of the Booking performance report: the bookings made in the dates for one room type, source, rate plan,
 * status or booking date. The property names are the column keys of `ReportKey::BookingPerformance`. `group` is the
 * row name, or the booking date (`Y-m-d`) when `grouping` is Day. `source` is set only when grouped by source and
 * `status` only when grouped by status, for the badge. `adr` and `lead_time` are null when the row has no room nights
 * or only walk-ins. `reservationIds`, `leadDays` and `leadUnits` let the total row count distinct bookings and work
 * out the lead time again; they are not sent to the page.
 */
class BookingPerformanceRow extends Data implements ReportRow
{
    /**
     * @param  list<int>  $reservationIds
     */
    public function __construct(
        public string $key,
        public ReportGroup $grouping,
        public string $group,
        public int $bookings,
        public int $room_nights,
        public string $revenue,
        public ?string $adr,
        public ?int $lead_time,
        public ?BookingSourceBadgeData $source = null,
        public ?BadgeData $status = null,
        #[Hidden, HiddenFromTypeScript]
        public array $reservationIds = [],
        #[Hidden, HiddenFromTypeScript]
        public int $leadDays = 0,
        #[Hidden, HiddenFromTypeScript]
        public int $leadUnits = 0,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'group' => $this->groupText(),
            'bookings' => (string) $this->bookings,
            'room_nights' => (string) $this->room_nights,
            'revenue' => number_format((float) $this->revenue, 2),
            'adr' => $this->adr === null ? '—' : number_format((float) $this->adr, 2),
            'lead_time' => $this->lead_time === null ? '—' : (string) $this->lead_time,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'group' => $this->grouping === ReportGroup::Day ? $this->group : $this->groupText(),
            'bookings' => $this->bookings,
            'room_nights' => $this->room_nights,
            'revenue' => round((float) $this->revenue, 2),
            'adr' => $this->adr === null ? '' : round((float) $this->adr, 2),
            'lead_time' => $this->lead_time ?? '',
        ];
    }

    private function groupText(): string
    {
        return $this->grouping === ReportGroup::Day ? CarbonImmutable::parse($this->group)->format('j M Y') : $this->group;
    }
}
