<?php

namespace App\Services\Reports;

use App\Data\Reports\HistoryForecastRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Data\Shared\BadgeData;
use App\Enums\ForecastBasis;
use App\Enums\UnitStatus;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * One row for each date of the range, past and future (Tanini's "Monthly statistics"). Read in a fixed number of
 * queries, whatever the range:
 *
 * - **Actual** (a closed date): the `daily_statistics` snapshot for rooms available, sold and pax, the complimentary
 *   nights of the units that stayed, and the ledger posted on the date. A closed date with no snapshot (before
 *   go-live) is left out, as on Occupancy.
 * - **Today** (the open business date) and **Forecast** (later dates): the books. Supply is the rooms and beds on sale
 *   (`RoomSupply`), sold and pax are the nights of the units that hold inventory, and the charges are what is
 *   posted so far plus `ChargeForecast` for what is not. A forecast date has no receipts.
 *
 * Room charges are the room revenue net of its discounts, extra charges every other charge, tax the tax lines.
 * Occupancy counts complimentary nights; ADR leaves them out (room charges over paid nights). RevPAR is room charges
 * over available. See docs/22-reports/04-history-and-forecast.md.
 */
class HistoryForecastReport implements HasTotals, Report
{
    public function __construct(
        private CurrentBusinessDate $businessDate,
        private DailyRevenueQuery $ledger,
        private BookedNightCounts $nightCounts,
        private ChargeForecast $chargeForecast,
        private RoomSupply $roomSupply,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<HistoryForecastRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $from = CarbonImmutable::parse($filters->from);
        $to = CarbonImmutable::parse($filters->to);
        $open = $this->businessDate->date($property);
        $firstForward = $open === null ? $from : $open->max($from);
        $lastActual = $open === null ? null : $open->subDay()->min($to);

        $rows = [
            ...($lastActual !== null && $lastActual->gte($from) ? $this->actualRows($property, $from, $lastActual) : []),
            ...($firstForward->lte($to) ? $this->forwardRows($property, $firstForward, $to, $open) : []),
        ];

        if ($rowKeys !== null) {
            $wanted = array_map('strval', $rowKeys);
            $rows = array_filter($rows, fn (HistoryForecastRow $row): bool => in_array($row->key, $wanted, true));
        }

        return array_values($rows);
    }

    /**
     * Sums of every count and amount. Occupancy, ADR and RevPAR are worked out again from the sums.
     *
     * @param  list<HistoryForecastRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $available = array_sum(array_map(fn (HistoryForecastRow $row): int => $row->available, $rows));
        $sold = array_sum(array_map(fn (HistoryForecastRow $row): int => $row->sold, $rows));
        $complimentary = array_sum(array_map(fn (HistoryForecastRow $row): int => $row->complimentary, $rows));
        $sum = fn (string $field): BigDecimal => array_reduce($rows, fn (BigDecimal $total, HistoryForecastRow $row): BigDecimal => $total->plus($row->{$field} ?? '0'), BigDecimal::zero());
        $room = $sum('room_charges');

        return new ReportTotals([
            'date' => null,
            'basis' => null,
            'available' => $available,
            'sold' => $sold,
            'complimentary' => $complimentary,
            'occupancy' => ReportFigures::percent($sold, $available),
            'adr' => ReportFigures::perUnit($room, $sold - $complimentary),
            'revpar' => ReportFigures::perUnit($room, $available),
            'pax' => array_sum(array_map(fn (HistoryForecastRow $row): int => $row->pax, $rows)),
            'room_charges' => ReportFigures::money($room),
            'extra_charges' => ReportFigures::money($sum('extra_charges')),
            'tax' => ReportFigures::money($sum('tax')),
            'receipts' => ReportFigures::money($sum('receipts')),
        ]);
    }

    /**
     * @return list<HistoryForecastRow>
     */
    private function actualRows(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ledger = $this->ledgerBetween($property, $from, $to);
        $receipts = $this->ledger->receiptsBetween($property, $from, $to);
        $complimentary = $this->nightCounts->between($property, $from, $to, [UnitStatus::CheckedIn, UnitStatus::CheckedOut]);

        return array_values(DailyStatistic::query()
            ->where('property_id', $property->id)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('business_date')
            ->get()
            ->map(function (DailyStatistic $statistic) use ($ledger, $receipts, $complimentary): HistoryForecastRow {
                $date = $statistic->business_date->toDateString();

                return $this->row(
                    $date,
                    ForecastBasis::Actual,
                    $statistic->rooms_available + $statistic->beds_available,
                    $statistic->rooms_sold + $statistic->beds_sold,
                    $complimentary[$date]['complimentary'] ?? 0,
                    $statistic->in_house_guests,
                    $ledger[$date] ?? null,
                    $receipts[$date] ?? '0',
                );
            })
            ->all());
    }

    /**
     * @return list<HistoryForecastRow>
     */
    private function forwardRows(Property $property, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $open): array
    {
        $supply = $this->roomSupply->between($property, $from, $to);
        $counts = $this->nightCounts->between($property, $from, $to, UnitStatus::holdingInventory());
        $forecast = $this->chargeForecast->between($property, $from, $to);
        $posted = $open !== null && $open->betweenIncluded($from, $to) ? $this->ledgerBetween($property, $open, $open) : [];
        $receipts = $open !== null && $open->betweenIncluded($from, $to) ? $this->ledger->receiptsBetween($property, $open, $open) : [];
        $rows = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $isToday = $open !== null && $day->isSameDay($open);
            $charges = $this->combine($posted[$date] ?? null, $forecast[$date] ?? null);

            $rows[] = $this->row(
                $date,
                $isToday ? ForecastBasis::Today : ForecastBasis::Forecast,
                $supply[$date] ?? 0,
                $counts[$date]['sold'] ?? 0,
                $counts[$date]['complimentary'] ?? 0,
                $counts[$date]['pax'] ?? 0,
                $charges,
                $isToday ? ($receipts[$date] ?? '0') : null,
            );
        }

        return $rows;
    }

    /**
     * The posted room charges, extra charges and tax of each date. Room is the room revenue net of its discounts; extra
     * is every other charge and adjustment.
     *
     * @return array<string, array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}>
     */
    private function ledgerBetween(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $room = $this->ledger->roomRevenueBetween($property, $from, $to);
        $totals = [];

        foreach ($this->ledger->bucketLinesBetween($property, $from, $to) as $line) {
            $totals[$line['date']] ??= ['room' => BigDecimal::zero(), 'extra' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
            $bucket = $line['kind'] === 'tax' ? 'tax' : 'extra';
            $totals[$line['date']][$bucket] = $totals[$line['date']][$bucket]->plus($line['amount']);
        }

        foreach ($room as $date => $amount) {
            $totals[$date] ??= ['room' => BigDecimal::zero(), 'extra' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
            $totals[$date]['room'] = $amount->getAmount();
            $totals[$date]['extra'] = $totals[$date]['extra']->minus($amount->getAmount());
        }

        return $totals;
    }

    /**
     * @param  array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}|null  $posted
     * @param  array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}|null  $forecast
     * @return array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}|null
     */
    private function combine(?array $posted, ?array $forecast): ?array
    {
        if ($posted === null || $forecast === null) {
            return $posted ?? $forecast;
        }

        return [
            'room' => $posted['room']->plus($forecast['room']),
            'extra' => $posted['extra']->plus($forecast['extra']),
            'tax' => $posted['tax']->plus($forecast['tax']),
        ];
    }

    /**
     * @param  array{room: BigDecimal, extra: BigDecimal, tax: BigDecimal}|null  $charges
     */
    private function row(string $date, ForecastBasis $basis, int $available, int $sold, int $complimentary, int $pax, ?array $charges, ?string $receipts): HistoryForecastRow
    {
        $room = $charges['room'] ?? BigDecimal::zero();

        return new HistoryForecastRow(
            key: $date,
            date: $date,
            basis: BadgeData::fromForecastBasis($basis),
            available: $available,
            sold: $sold,
            complimentary: $complimentary,
            occupancy: ReportFigures::percent($sold, $available),
            adr: ReportFigures::perUnit($room, $sold - $complimentary),
            revpar: ReportFigures::perUnit($room, $available),
            pax: $pax,
            room_charges: ReportFigures::money($room),
            extra_charges: ReportFigures::money($charges['extra'] ?? BigDecimal::zero()),
            tax: ReportFigures::money($charges['tax'] ?? BigDecimal::zero()),
            receipts: $receipts === null ? null : ReportFigures::money(BigDecimal::of($receipts)),
        );
    }
}
