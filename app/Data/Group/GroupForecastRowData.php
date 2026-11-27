<?php

namespace App\Data\Group;

use Spatie\LaravelData\Data;

/**
 * One month of the group forecast, for one property (`code`) or for all of them (`code` null). This year's figures are
 * actual for closed dates and on the books for the rest; `basis` says which (`actual`, `mixed` or `forecast`). The last
 * year figures are the same month a year before, from the night audit (null when that month has no closed dates).
 * Money is null in the all-properties row when the properties use different currencies.
 * See docs/03-staff/05-group-mode.md#forecast.
 */
class GroupForecastRowData extends Data
{
    public function __construct(
        public string $month,
        public ?string $code,
        public string $basis,
        public int $available,
        public int $sold,
        public string $occupancy,
        public ?string $adr,
        public ?string $revpar,
        public int $pax,
        public ?string $roomRevenue,
        public ?string $extraRevenue,
        public ?string $totalRevenue,
        public ?int $lastYearAvailable,
        public ?int $lastYearSold,
        public ?string $lastYearOccupancy,
        public ?string $lastYearRoomRevenue,
    ) {}

    /**
     * @return list<string>
     */
    public static function csvHeadings(): array
    {
        return [__('Month'), __('Property'), __('Basis'), __('Available'), __('Sold'), __('Occupancy %'), __('ADR'), __('RevPAR'), __('Pax'), __('Room revenue'), __('Extra revenue'), __('Total revenue'), __('Sold last year'), __('Occupancy % last year'), __('Room revenue last year')];
    }

    /**
     * @return list<string|int|null>
     */
    public function csv(string $propertyName): array
    {
        return [$this->month, $propertyName, $this->basis, $this->available, $this->sold, $this->occupancy, $this->adr, $this->revpar, $this->pax, $this->roomRevenue, $this->extraRevenue, $this->totalRevenue, $this->lastYearSold, $this->lastYearOccupancy, $this->lastYearRoomRevenue];
    }
}
