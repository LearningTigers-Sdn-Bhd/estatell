<?php

namespace App\Actions\NightAudit;

use App\Models\DailyRevenue;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Services\NightAudit\StatisticsCalculator;
use App\Services\Reports\DailyRevenueQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SnapshotStatistics
{
    public function __construct(
        private StatisticsCalculator $statisticsCalculator,
        private DailyRevenueQuery $dailyRevenueQuery,
    ) {}

    /**
     * Write the statistics and the revenue by code of one business date. Both tables are upserted on their unique
     * keys, and a revenue row of a code that no longer has lines is removed, so a resumed run writes the same rows.
     * See docs/20-night-audit/05-statistics.md.
     */
    public function __invoke(Property $property, CarbonImmutable $date): void
    {
        $figures = $this->statisticsCalculator->forDate($property, $date);
        $revenue = $this->dailyRevenueQuery->forDate($property, $date);
        $day = $date->toDateString();
        $now = now();

        DB::transaction(function () use ($property, $day, $figures, $revenue, $now): void {
            DailyStatistic::query()->upsert([[
                'property_id' => $property->id,
                'business_date' => $day,
                'rooms_available' => $figures->roomsAvailable,
                'rooms_sold' => $figures->roomsSold,
                'beds_available' => $figures->bedsAvailable,
                'beds_sold' => $figures->bedsSold,
                'occupancy' => $figures->occupancy,
                'adr' => $figures->adr->getAmount(),
                'revpar' => $figures->revpar->getAmount(),
                'arrivals' => $figures->arrivals,
                'departures' => $figures->departures,
                'no_shows' => $figures->noShows,
                'in_house_guests' => $figures->inHouseGuests,
                'day_use_sold' => $figures->dayUseSold,
                'day_use_revenue' => $figures->dayUseRevenue->getAmount(),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['property_id', 'business_date'], [
                'rooms_available', 'rooms_sold', 'beds_available', 'beds_sold', 'occupancy', 'adr', 'revpar',
                'arrivals', 'departures', 'no_shows', 'in_house_guests', 'day_use_sold', 'day_use_revenue', 'updated_at',
            ]);

            DailyRevenue::query()
                ->where('property_id', $property->id)
                ->whereDate('business_date', $day)
                ->whereNotIn('transaction_code_id', $revenue->pluck('transactionCodeId'))
                ->delete();

            if ($revenue->isEmpty()) {
                return;
            }

            DailyRevenue::query()->upsert(
                $revenue->map(fn ($row): array => [
                    'property_id' => $property->id,
                    'business_date' => $day,
                    'transaction_code_id' => $row->transactionCodeId,
                    'amount' => $row->amount->getAmount(),
                    'line_count' => $row->lineCount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['property_id', 'business_date', 'transaction_code_id'],
                ['amount', 'line_count', 'updated_at'],
            );
        });
    }
}
