<?php

namespace App\Services\NightAudit;

use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Services\Availability\AvailabilityService;
use App\Services\Reports\DailyRevenueQuery;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Works out the statistics of one business date from the source data. It reads only and writes nothing.
 *
 * - Supply is the active rooms (or beds) of each room type that no open room block takes off the night. A room type
 *   that sells beds counts in the bed fields only.
 * - Sold units are the rooms and beds that a CheckedIn or CheckedOut unit sleeps in on the night. A unit checked out
 *   before the audit still holds the night it slept, because an early check-out deletes the nights it did not use.
 * - Occupancy, ADR and RevPAR count a room or a bed as one unit. Revenue is the net room revenue of
 *   `DailyRevenueQuery`.
 * - Arrivals, departures and no-shows are the front desk events logged with the business date, one for each unit.
 * - In-house guests are the adults and children of the CheckedIn units.
 * - Day use is counted apart: the CheckedIn and CheckedOut day use units of the date that still hold their block, and
 *   the DAY_USE revenue of the date. Neither is in occupancy, ADR or RevPAR.
 *
 * See docs/20-night-audit/05-statistics.md.
 */
class StatisticsCalculator
{
    public function __construct(
        private AvailabilityService $availability,
        private DailyRevenueQuery $revenue,
    ) {}

    public function forDate(Property $property, CarbonImmutable $date): StatisticsFigures
    {
        [$roomsAvailable, $bedsAvailable] = $this->supply($property, $date);
        $nights = $this->sleptNights($property, $date);
        $roomsSold = $nights->pluck('room_id')->filter()->unique()->count();
        $bedsSold = $nights->pluck('bed_id')->filter()->unique()->count();

        $sold = $roomsSold + $bedsSold;
        $available = $roomsAvailable + $bedsAvailable;
        $roomRevenue = $this->revenue->roomRevenue($property, $date);
        $zero = Money::zero($roomRevenue->getCurrency());

        return new StatisticsFigures(
            $roomsAvailable,
            $roomsSold,
            $bedsAvailable,
            $bedsSold,
            $available === 0 ? '0.00' : (string) BigDecimal::of($sold)->multipliedBy(100)->dividedBy($available, 2, RoundingMode::HalfUp),
            $sold === 0 ? $zero : $roomRevenue->dividedBy($sold, RoundingMode::HalfUp),
            $available === 0 ? $zero : $roomRevenue->dividedBy($available, RoundingMode::HalfUp),
            $this->events('reservation.unit_checked_in', $property, $date),
            $this->events('reservation.unit_checked_out', $property, $date),
            $this->events('reservation.unit_no_show', $property, $date),
            $this->inHouseGuests($property),
            $this->dayUseSold($property, $date),
            $this->revenue->dayUseRevenue($property, $date),
        );
    }

    /**
     * The rooms and the beds on sale on the night.
     *
     * @return array{int, int}
     */
    private function supply(Property $property, CarbonImmutable $date): array
    {
        $rooms = 0;
        $beds = 0;

        foreach (RoomType::query()->where('property_id', $property->id)->get() as $roomType) {
            $night = $this->availability->nights($roomType, $date, $date->addDay())[0];
            $onSale = max(0, $night->total - $night->blocked);

            if ($roomType->sell_unit === SellUnit::Bed) {
                $beds += $onSale;
            } else {
                $rooms += $onSale;
            }
        }

        return [$rooms, $beds];
    }

    /**
     * The night rows of the CheckedIn and CheckedOut units that sleep on the date.
     *
     * @return EloquentCollection<int, UnitNight>
     */
    private function sleptNights(Property $property, CarbonImmutable $date): Collection
    {
        return UnitNight::query()
            ->whereDate('date', $date->toDateString())
            ->whereHas('reservationUnit', fn ($units) => $units->where('property_id', $property->id)->whereIn('status', [UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value]))
            ->get(['room_id', 'bed_id']);
    }

    private function inHouseGuests(Property $property): int
    {
        return (int) ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::CheckedIn->value)
            ->sum(DB::raw('adults + children'));
    }

    /**
     * The day use units of the date that were used: CheckedIn or CheckedOut, and not released (a cancelled or no-show
     * block is released). One for each unit, not for each hour.
     */
    private function dayUseSold(Property $property, CarbonImmutable $date): int
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('stay_type', StayType::DayUse->value)
            ->whereIn('status', [UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value])
            ->whereHas('dayUse', fn ($blocks) => $blocks->whereDate('date', $date->toDateString())->whereNull('released_at'))
            ->count();
    }

    /**
     * How many units a front desk event was logged for on the business date.
     */
    private function events(string $event, Property $property, CarbonImmutable $date): int
    {
        return (int) Activity::query()
            ->where('event', $event)
            ->where('properties->property_id', (string) $property->id)
            ->where('properties->business_date', $date->toDateString())
            ->selectRaw("COUNT(DISTINCT properties->>'reservation_unit_id') as total")
            ->value('total');
    }
}
