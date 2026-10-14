<?php

namespace App\Services\Reservations;

use App\Data\Boats\BoatSetupUnitData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\BoatDirection;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Services\Boats\BoatTripAccess;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The open units of a reservation for the Set boats Dialog: Confirmed and CheckedIn, not day use, each with its boat trips
 * loaded in one query. Reads only.
 */
class ReservationBoatSetup
{
    public function __construct(
        private ReservationRoomBox $roomBox,
        private BoatTripAccess $boatTripAccess,
    ) {}

    /**
     * @param  EloquentCollection<int, ReservationUnit>  $units
     * @return list<BoatSetupUnitData>
     */
    public function forUnits(Collection $units, bool $canManage): array
    {
        $open = $units
            ->filter(fn (ReservationUnit $unit): bool => $unit->stay_type !== StayType::DayUse && in_array($unit->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true))
            ->values();

        if ($open->isEmpty()) {
            return [];
        }

        $open->loadMissing(['roomType', 'boatTrips.boatDeparture']);
        $assigned = $this->roomBox->assignmentsForUnits($open->pluck('id')->all());

        return array_values($open
            ->map(fn (ReservationUnit $unit): BoatSetupUnitData => BoatSetupUnitData::fromModel(
                $unit,
                ReservationUnitOptionData::fromModel($unit, $assigned[$unit->id] ?? null)->label,
                $canManage && $this->boatTripAccess->refusalFor($unit, BoatDirection::In) === null,
                $canManage && $this->boatTripAccess->refusalFor($unit, BoatDirection::Out) === null,
            ))
            ->all());
    }
}
