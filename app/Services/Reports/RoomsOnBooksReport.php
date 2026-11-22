<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Data\Reports\RoomsOnBooksRow;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rooms on books › On the books (Tanini's "Rooms On Books" grid): one row for each date with the rooms full that
 * morning, the arrivals, departures and group arrivals, the rooms booked for the night, occupancy and the average
 * booked rate. It counts the overnight units that are booked (Confirmed, CheckedIn or CheckedOut), as they stand now,
 * so a past date shows what stayed and a future date what is on the books. Read in a fixed number of queries.
 * See docs/22-reports/05-rooms-on-books.md.
 */
class RoomsOnBooksReport implements HasTotals, Report
{
    public function __construct(
        private BookedNightCounts $nightCounts,
        private RoomSupply $roomSupply,
    ) {}

    /**
     * The unit statuses that count as booked.
     *
     * @return list<UnitStatus>
     */
    public static function bookedStatuses(): array
    {
        return [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut];
    }

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<RoomsOnBooksRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $from = CarbonImmutable::parse($filters->from);
        $to = CarbonImmutable::parse($filters->to);
        $nights = $this->nightCounts->between($property, $from->subDay(), $to, self::bookedStatuses());
        $supply = $this->roomSupply->between($property, $from, $to);
        $arrivals = $this->movements($property, 'arrival_date', $from, $to);
        $departures = $this->movements($property, 'departure_date', $from, $to);
        $wanted = $rowKeys === null ? null : array_map('strval', $rowKeys);
        $rows = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($wanted !== null && ! in_array($date, $wanted, true)) {
                continue;
            }

            $night = $nights[$date] ?? null;
            $sold = $night['sold'] ?? 0;
            $complimentary = $night['complimentary'] ?? 0;
            $revenue = BigDecimal::of($night['paid_amount'] ?? '0');

            $rows[] = new RoomsOnBooksRow(
                key: $date,
                date: $date,
                in_house: $nights[$day->subDay()->toDateString()]['sold'] ?? 0,
                arrivals: $arrivals[$date]['units'] ?? 0,
                departures: $departures[$date]['units'] ?? 0,
                group_arrivals: $arrivals[$date]['group_units'] ?? 0,
                rooms_on_books: $sold,
                complimentary: $complimentary,
                available: $supply[$date] ?? 0,
                occupancy: $this->percent($sold, $supply[$date] ?? 0),
                arr: $this->divide($revenue, $sold - $complimentary),
                room_revenue: (string) $revenue->toScale(2, RoundingMode::HalfUp),
            );
        }

        return $rows;
    }

    /**
     * The sums of the movements and room nights. Occupancy and ARR are worked out again from the sums. In-house is a
     * daily figure, so it has no total.
     *
     * @param  list<RoomsOnBooksRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $sum = fn (string $field): int => array_sum(array_map(fn (RoomsOnBooksRow $row): int => $row->{$field}, $rows));
        $revenue = array_reduce($rows, fn (BigDecimal $total, RoomsOnBooksRow $row): BigDecimal => $total->plus($row->room_revenue), BigDecimal::zero());
        $sold = $sum('rooms_on_books');

        return new ReportTotals([
            'date' => null,
            'in_house' => null,
            'arrivals' => $sum('arrivals'),
            'departures' => $sum('departures'),
            'group_arrivals' => $sum('group_arrivals'),
            'rooms_on_books' => $sold,
            'complimentary' => $sum('complimentary'),
            'available' => $sum('available'),
            'occupancy' => $this->percent($sold, $sum('available')),
            'arr' => $this->divide($revenue, $sold - $sum('complimentary')),
            'room_revenue' => (string) $revenue->toScale(2, RoundingMode::HalfUp),
        ]);
    }

    /**
     * The booked overnight units arriving (or departing) on each date, and how many of them are on a group booking.
     *
     * @param  'arrival_date'|'departure_date'  $column
     * @return array<string, array{units: int, group_units: int}>
     */
    private function movements(Property $property, string $column, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('reservation_units')
            ->join('reservations', 'reservations.id', '=', 'reservation_units.reservation_id')
            ->where('reservation_units.property_id', $property->id)
            ->where('reservation_units.stay_type', StayType::Overnight->value)
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, self::bookedStatuses()))
            ->whereBetween("reservation_units.{$column}", [$from->toDateString(), $to->toDateString()])
            ->groupBy("reservation_units.{$column}")
            ->selectRaw("reservation_units.{$column} as date, COUNT(*) as units, COUNT(*) FILTER (WHERE reservations.group_name IS NOT NULL) as group_units")
            ->get()
            ->mapWithKeys(fn (object $row): array => [CarbonImmutable::parse((string) $row->date)->toDateString() => [
                'units' => (int) $row->units,
                'group_units' => (int) $row->group_units,
            ]])
            ->all();
    }

    private function percent(int $part, int $whole): string
    {
        return $whole === 0 ? '0.00' : (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);
    }

    private function divide(BigDecimal $amount, int $by): string
    {
        return $by <= 0 ? '0.00' : (string) $amount->dividedBy($by, 2, RoundingMode::HalfUp);
    }
}
