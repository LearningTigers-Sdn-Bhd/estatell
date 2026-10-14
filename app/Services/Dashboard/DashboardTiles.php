<?php

namespace App\Services\Dashboard;

use App\Data\Dashboard\DashboardTilesData;
use App\Data\Dashboard\MonthTileData;
use App\Data\Dashboard\TonightNightData;
use App\Data\Dashboard\TonightRoomTypeData;
use App\Data\Dashboard\TonightTileData;
use App\Data\Dashboard\UnitCountTileData;
use App\Enums\SellUnit;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Services\Availability\AvailabilityService;
use App\Services\Reports\DailyRevenueQuery;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The figures of the dashboard tiles for one business date. Each tile is read in grouped queries, so the number of
 * queries does not grow with units or room types. A tile the caller may not see is null. It reads only.
 * See docs/18-front-desk/04-dashboard.md#tiles.
 */
class DashboardTiles
{
    private const NIGHTS = 7;

    public function __construct(
        private AvailabilityService $availability,
        private DailyRevenueQuery $revenue,
    ) {}

    public function forDate(Property $property, CarbonImmutable $date, bool $units, bool $reports): DashboardTilesData
    {
        return new DashboardTilesData(
            date: $date->toDateString(),
            arrivals: $units ? $this->unitCount($property, 'arrival_date', $date, UnitStatus::Confirmed) : null,
            departures: $units ? $this->unitCount($property, 'departure_date', $date, UnitStatus::CheckedIn) : null,
            tonight: $units ? $this->tonight($property, $date) : null,
            month: $reports ? $this->month($property, $date) : null,
        );
    }

    /**
     * Units that hold inventory and arrive or depart on the date, as the Arrivals and Departures reports count them,
     * and how many of them are still in the status that needs action.
     */
    private function unitCount(Property $property, string $column, CarbonImmutable $date, UnitStatus $remaining): UnitCountTileData
    {
        $counts = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where($column, $date->toDateString())
            ->whereIn('status', UnitStatus::holdingInventory())
            ->selectRaw('status, COUNT(*) as units')
            ->groupBy('status')
            ->pluck('units', 'status');

        return new UnitCountTileData((int) ($counts[$remaining->value] ?? 0), (int) $counts->sum());
    }

    private function tonight(Property $property, CarbonImmutable $date): TonightTileData
    {
        $roomTypes = RoomType::query()->where('property_id', $property->id)->active()->ordered()->get();
        $byType = $this->availability->nightsForRoomTypes($roomTypes, $date, $date->addDays(self::NIGHTS));

        $nights = [];

        for ($index = 0; $index < self::NIGHTS; $index++) {
            $sold = 0;
            $total = 0;

            foreach ($roomTypes as $roomType) {
                $night = $byType[$roomType->id][$index];
                $sold += $night->booked;
                $total += max(0, $night->total - $night->blocked);
            }

            $nights[] = new TonightNightData(
                $date->addDays($index)->toDateString(),
                $total === 0 ? 0 : min(100, (int) round($sold / $total * 100)),
                $sold,
                $total,
            );
        }

        $roomsFree = 0;
        $bedsFree = null;
        $types = [];

        foreach ($roomTypes as $roomType) {
            $night = $byType[$roomType->id][0];

            if ($roomType->sell_unit === SellUnit::Bed) {
                $bedsFree = ($bedsFree ?? 0) + $night->available;
            } else {
                $roomsFree += $night->available;
            }

            $types[] = new TonightRoomTypeData($roomType->name, $roomType->sell_unit, $night->booked, max(0, $night->total - $night->blocked), $night->available);
        }

        return new TonightTileData($nights[0]->occupancy, $roomsFree, $bedsFree, $nights, $types);
    }

    /**
     * Posted is the revenue report for the month to date without tax lines. Booked is the room nights of live units
     * by stay date in the whole month, past and future.
     */
    private function month(Property $property, CarbonImmutable $date): MonthTileData
    {
        $from = $date->startOfMonth();
        $currency = $property->loadMissing('settings')->settings?->currency;

        $taxCodeIds = TransactionCode::query()
            ->where('property_id', $property->id)
            ->where('kind', TransactionKind::Tax->value)
            ->pluck('id')
            ->all();

        $posted = $this->revenue->between($property, $from, $date)
            ->reject(fn ($row): bool => in_array($row->transactionCodeId, $taxCodeIds, true))
            ->reduce(fn (Money $sum, $row): Money => $sum->plus($row->amount), Money::zero($currency->value));

        $booked = UnitNight::query()
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.property_id', $property->id)
            ->whereIn('reservation_units.status', UnitStatus::holdingInventory())
            ->whereBetween('unit_nights.date', [$from->toDateString(), $date->endOfMonth()->toDateString()])
            ->selectRaw('COUNT(*) as nights, COALESCE(SUM(unit_nights.amount), 0) as amount')
            ->first();

        return new MonthTileData(
            month: $date->translatedFormat('F'),
            from: $from->toDateString(),
            to: $date->toDateString(),
            currency: $currency,
            posted: (string) $posted->getAmount(),
            bookedNights: (int) $booked->getAttribute('nights'),
            bookedAmount: number_format((float) $booked->getAttribute('amount'), 2, '.', ''),
        );
    }
}
