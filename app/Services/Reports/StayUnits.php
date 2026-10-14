<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The reservation units that stayed in a date range, for the Non-national guests and Police reports: Checked in and
 * Checked out units with arrival on or before the last date and departure after the first date, or a day use on a date in the range (it arrives and leaves the same day). A Confirmed unit has
 * not arrived and a Cancelled or No-show unit never did, so they are left out. An early check-out has already moved the
 * departure date, so the dates alone give the nights that were stayed.
 */
class StayUnits
{
    /**
     * @param  list<int|string>|null  $unitIds  Only these units, when given.
     * @return EloquentCollection<int, ReservationUnit> With the reservation, its booker and the occupants loaded, in unit id order.
     */
    public function between(Property $property, ReportFilters $filters, ?array $unitIds = null): Collection
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('arrival_date', '<=', $filters->to)
            ->where(fn (Builder $stay) => $stay
                ->where('departure_date', '>', $filters->from)
                ->orWhere(fn (Builder $dayUse) => $dayUse
                    ->where('stay_type', StayType::DayUse->value)
                    ->where('arrival_date', '>=', $filters->from)))
            ->whereIn('status', [UnitStatus::CheckedIn, UnitStatus::CheckedOut])
            ->when($unitIds !== null, fn (Builder $query) => $query->whereIn('id', $unitIds))
            ->with(['reservation.booker', 'guests.guest'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The nights of the stay that fall inside the range: the arrival night up to the night before departure.
     */
    public function nightsInRange(ReservationUnit $unit, ReportFilters $filters): int
    {
        $first = CarbonImmutable::parse($unit->arrival_date->toDateString())->max(CarbonImmutable::parse($filters->from));
        $end = CarbonImmutable::parse($unit->departure_date->toDateString())->min(CarbonImmutable::parse($filters->to)->addDay());

        return max(0, (int) $first->diffInDays($end));
    }
}
