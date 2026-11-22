<?php

namespace App\Services\Reports;

use App\Data\Reports\BookingChangeRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\RoomsOnBooksImpactData;
use App\Data\Reports\RoomsOnBooksLengthOfStayData;
use App\Data\Reports\RoomsOnBooksOverviewData;
use App\Data\Reports\RoomsOnBooksPaceData;
use App\Data\Reports\RoomsOnBooksRecentDayData;
use App\Enums\BookingChangeKind;
use App\Enums\UnitStatus;
use App\Models\DailyStatistic;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Rooms on books › Overview: the impact of the changes made in the dates and the pick-up by length of stay (both from
 * the Changes rows, so they match that tab), the pace of the next 30 days from the books, and the last 10 closed
 * dates from the night audit snapshots. See docs/22-reports/05-rooms-on-books.md#overview.
 */
class RoomsOnBooksOverview
{
    /** @var list<array{label: string, min: int, max: int|null}> */
    private const LENGTH_OF_STAY_BANDS = [
        ['label' => '1–3 nights', 'min' => 1, 'max' => 3],
        ['label' => '4–7 nights', 'min' => 4, 'max' => 7],
        ['label' => '8–10 nights', 'min' => 8, 'max' => 10],
        ['label' => '11+ nights', 'min' => 11, 'max' => null],
    ];

    /** @var list<array{label: string, offset: int, days: int}> */
    private const PACE_WINDOWS = [
        ['label' => 'Next 10 days', 'offset' => 0, 'days' => 10],
        ['label' => '11–20 days', 'offset' => 10, 'days' => 10],
        ['label' => '21–30 days', 'offset' => 20, 'days' => 10],
        ['label' => 'Next 30 days', 'offset' => 0, 'days' => 30],
    ];

    public function __construct(
        private BookingChangesReport $changes,
        private BookedNightCounts $nightCounts,
        private RoomSupply $roomSupply,
    ) {}

    public function forDates(Property $property, ReportFilters $filters, CarbonImmutable $asOf): RoomsOnBooksOverviewData
    {
        $rows = $this->changes->rows($property, $filters);

        return new RoomsOnBooksOverviewData(
            asOf: $asOf->toDateString(),
            impact: $this->impact($rows),
            pace: $this->pace($property, $asOf),
            recent: $this->recent($property, $asOf),
            lengthOfStay: $this->lengthOfStay($rows),
        );
    }

    /**
     * @param  list<BookingChangeRow>  $rows
     * @return list<RoomsOnBooksImpactData>
     */
    private function impact(array $rows): array
    {
        $lines = [];

        foreach (BookingChangeKind::cases() as $kind) {
            $lines[] = $this->impactLine($kind->value, $kind->pluralLabel(), array_values(array_filter($rows, fn (BookingChangeRow $row): bool => $row->kind->value === $kind->value)));
        }

        $lines[] = $this->impactLine(null, __('Net'), $rows);

        return $lines;
    }

    /**
     * @param  list<BookingChangeRow>  $rows
     */
    private function impactLine(?string $kind, string $label, array $rows): RoomsOnBooksImpactData
    {
        $nights = array_sum(array_map(fn (BookingChangeRow $row): int => $row->nights_change, $rows));
        $revenue = array_reduce($rows, fn (BigDecimal $sum, BookingChangeRow $row): BigDecimal => $sum->plus($row->revenue_change), BigDecimal::zero());

        return new RoomsOnBooksImpactData(
            kind: $kind,
            label: $label,
            rooms: count($rows),
            nights: $nights,
            revenue: $this->money($revenue),
            arr: $nights === 0 ? '0.00' : (string) $revenue->abs()->dividedBy(abs($nights), 2, RoundingMode::HalfUp),
        );
    }

    /**
     * The room nights that hold inventory in each window from the business date.
     *
     * @return list<RoomsOnBooksPaceData>
     */
    private function pace(Property $property, CarbonImmutable $asOf): array
    {
        $last = $asOf->addDays(29);
        $nights = $this->nightCounts->between($property, $asOf, $last, UnitStatus::holdingInventory());
        $supply = $this->roomSupply->between($property, $asOf, $last);

        return array_map(function (array $window) use ($asOf, $nights, $supply): RoomsOnBooksPaceData {
            $from = $asOf->addDays($window['offset']);
            $to = $from->addDays($window['days'] - 1);
            $sold = 0;
            $available = 0;

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $sold += $nights[$day->toDateString()]['sold'] ?? 0;
                $available += $supply[$day->toDateString()] ?? 0;
            }

            return new RoomsOnBooksPaceData(
                label: __($window['label']),
                from: $from->toDateString(),
                to: $to->toDateString(),
                nights: $sold,
                rpd: (string) BigDecimal::of($sold)->dividedBy($window['days'], 2, RoundingMode::HalfUp),
                occupancy: $this->percent($sold, $available),
            );
        }, self::PACE_WINDOWS);
    }

    /**
     * @return list<RoomsOnBooksRecentDayData>
     */
    private function recent(Property $property, CarbonImmutable $asOf): array
    {
        return array_values(DailyStatistic::query()
            ->where('property_id', $property->id)
            ->whereBetween('business_date', [$asOf->subDays(10)->toDateString(), $asOf->subDay()->toDateString()])
            ->orderBy('business_date')
            ->get()
            ->map(fn (DailyStatistic $statistic): RoomsOnBooksRecentDayData => new RoomsOnBooksRecentDayData(
                date: $statistic->business_date->toDateString(),
                sold: $statistic->rooms_sold + $statistic->beds_sold,
                occupancy: $this->percent($statistic->rooms_sold + $statistic->beds_sold, $statistic->rooms_available + $statistic->beds_available),
            ))
            ->all());
    }

    /**
     * @param  list<BookingChangeRow>  $rows
     * @return list<RoomsOnBooksLengthOfStayData>
     */
    private function lengthOfStay(array $rows): array
    {
        $bookings = array_filter($rows, fn (BookingChangeRow $row): bool => $row->kind->value === BookingChangeKind::NewBooking->value);

        return array_map(function (array $band) use ($bookings): RoomsOnBooksLengthOfStayData {
            $inBand = array_filter($bookings, fn (BookingChangeRow $row): bool => $row->nights >= $band['min'] && ($band['max'] === null || $row->nights <= $band['max']));
            $nights = array_sum(array_map(fn (BookingChangeRow $row): int => $row->nights, $inBand));
            $revenue = array_reduce($inBand, fn (BigDecimal $sum, BookingChangeRow $row): BigDecimal => $sum->plus($row->revenue), BigDecimal::zero());

            return new RoomsOnBooksLengthOfStayData(
                label: __($band['label']),
                rooms: count($inBand),
                nights: $nights,
                rate: $nights === 0 ? '0.00' : (string) $revenue->dividedBy($nights, 2, RoundingMode::HalfUp),
                revenue: $this->money($revenue),
            );
        }, self::LENGTH_OF_STAY_BANDS);
    }

    private function percent(int $part, int $whole): string
    {
        return $whole === 0 ? '0.00' : (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }

    private function money(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }
}
