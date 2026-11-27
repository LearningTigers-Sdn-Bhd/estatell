<?php

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingStatus;
use App\Models\Bed;
use App\Models\BedStatus;
use App\Models\Room;

class CreateBedStatus
{
    /**
     * Give a new bed its housekeeping status: its room's status, Clean when the room has none.
     * See docs/19-housekeeping/04-dorm-beds.md.
     */
    public function __invoke(Bed $bed, Room $room): BedStatus
    {
        $status = new BedStatus;
        $status->forceFill([
            'property_id' => $room->property_id,
            'bed_id' => $bed->id,
            'status' => $room->loadMissing('status')->status->status ?? HousekeepingStatus::Clean,
        ])->save();

        return $status;
    }
}
