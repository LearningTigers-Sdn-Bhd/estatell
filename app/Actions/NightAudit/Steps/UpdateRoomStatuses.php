<?php

namespace App\Actions\NightAudit\Steps;

use App\Enums\HousekeepingStatus;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\NightAudit;
use App\Models\RoomStatus;
use App\Models\UnitNight;
use Carbon\CarbonImmutable;

class UpdateRoomStatuses implements AuditStepRunner
{
    /**
     * Mark every room that sleeps a CheckedIn unit on the night of the business date Dirty, so housekeeping cleans it
     * the next morning. A bed gives its room. A room that departed is already Dirty from check-out. Rooms that are
     * already Dirty are not touched.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        $nights = UnitNight::query()
            ->whereDate('date', $date->toDateString())
            ->whereHas('reservationUnit', fn ($units) => $units->where('property_id', $audit->property_id)->where('status', UnitStatus::CheckedIn->value))
            ->get(['room_id', 'bed_id']);

        $roomIds = $nights->pluck('room_id')->filter()
            ->merge(Bed::query()->whereIn('id', $nights->pluck('bed_id')->filter())->pluck('room_id'))
            ->unique()
            ->values();

        $updated = RoomStatus::query()
            ->where('property_id', $audit->property_id)
            ->whereIn('room_id', $roomIds)
            ->where('status', '!=', HousekeepingStatus::Dirty->value)
            ->update([
                'status' => HousekeepingStatus::Dirty->value,
                'last_changed_by' => null,
                'last_changed_at' => now(),
            ]);

        return new StepOutcome($updated);
    }
}
