<?php

namespace App\Data\Reports;

use App\Models\DailyStatistic;
use App\Services\NightAudit\StatisticsFigures;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Occupancy report: one business date. The property names are the column keys of
 * `ReportKey::Occupancy`. `key` is the date. `available` and `sold` count rooms and beds as one unit each, like the
 * occupancy figure. `live` is true for the open business date, which is not closed yet.
 */
class OccupancyRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $date,
        public bool $live,
        public int $available,
        public int $sold,
        public string $occupancy,
        public string $adr,
        public string $revpar,
        public string $revenue,
        public int $day_use_sold,
        public string $day_use_revenue,
        public int $arrivals,
        public int $departures,
        public int $noshows,
        public int $guests,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        $date = CarbonImmutable::parse($this->date)->format('j M Y');

        return [
            'date' => $this->live ? "{$date} (".__('live').')' : $date,
            'available' => (string) $this->available,
            'sold' => (string) $this->sold,
            'occupancy' => number_format((float) $this->occupancy, 2),
            'adr' => number_format((float) $this->adr, 2),
            'revpar' => number_format((float) $this->revpar, 2),
            'revenue' => number_format((float) $this->revenue, 2),
            'day_use_sold' => (string) $this->day_use_sold,
            'day_use_revenue' => number_format((float) $this->day_use_revenue, 2),
            'arrivals' => (string) $this->arrivals,
            'departures' => (string) $this->departures,
            'noshows' => (string) $this->noshows,
            'guests' => (string) $this->guests,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->live ? "{$this->date} (".__('live').')' : $this->date,
            'available' => $this->available,
            'sold' => $this->sold,
            'occupancy' => round((float) $this->occupancy, 2),
            'adr' => round((float) $this->adr, 2),
            'revpar' => round((float) $this->revpar, 2),
            'revenue' => round((float) $this->revenue, 2),
            'day_use_sold' => $this->day_use_sold,
            'day_use_revenue' => round((float) $this->day_use_revenue, 2),
            'arrivals' => $this->arrivals,
            'departures' => $this->departures,
            'noshows' => $this->noshows,
            'guests' => $this->guests,
        ];
    }

    /**
     * A closed date, from its `daily_statistics` row.
     */
    public static function fromStatistic(DailyStatistic $statistic, Money $revenue): self
    {
        return new self(
            key: $statistic->business_date->toDateString(),
            date: $statistic->business_date->toDateString(),
            live: false,
            available: $statistic->rooms_available + $statistic->beds_available,
            sold: $statistic->rooms_sold + $statistic->beds_sold,
            occupancy: $statistic->occupancy,
            adr: $statistic->adr,
            revpar: $statistic->revpar,
            revenue: (string) $revenue->getAmount(),
            day_use_sold: $statistic->day_use_sold,
            day_use_revenue: $statistic->day_use_revenue,
            arrivals: $statistic->arrivals,
            departures: $statistic->departures,
            noshows: $statistic->no_shows,
            guests: $statistic->in_house_guests,
        );
    }

    /**
     * The open business date, from the figures `StatisticsCalculator` works out now.
     */
    public static function fromFigures(CarbonImmutable $date, StatisticsFigures $figures, Money $revenue): self
    {
        return new self(
            key: $date->toDateString(),
            date: $date->toDateString(),
            live: true,
            available: $figures->roomsAvailable + $figures->bedsAvailable,
            sold: $figures->roomsSold + $figures->bedsSold,
            occupancy: $figures->occupancy,
            adr: (string) $figures->adr->getAmount(),
            revpar: (string) $figures->revpar->getAmount(),
            revenue: (string) $revenue->getAmount(),
            day_use_sold: $figures->dayUseSold,
            day_use_revenue: (string) $figures->dayUseRevenue->getAmount(),
            arrivals: $figures->arrivals,
            departures: $figures->departures,
            noshows: $figures->noShows,
            guests: $figures->inHouseGuests,
        );
    }
}
