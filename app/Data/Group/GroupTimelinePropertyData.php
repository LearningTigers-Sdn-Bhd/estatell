<?php

namespace App\Data\Group;

use App\Data\Property\PropertySummaryData;
use App\Data\StayView\BlockData;
use App\Data\StayView\DayUseData;
use App\Data\StayView\RoomRowData;
use App\Data\StayView\StayData;
use App\Data\StayView\StayViewWindowData;
use Spatie\LaravelData\Data;

/**
 * One property on the group timeline: its own Timeline View data for the dates (`StayViewQuery`, without rates), read
 * only. `canViewReservations` (the role there) decides whether a bar opens the booking.
 * See docs/03-staff/05-group-mode.md#group-timeline.
 */
class GroupTimelinePropertyData extends Data
{
    /**
     * @param  list<RoomRowData>  $rooms
     * @param  list<StayData>  $stays
     * @param  list<DayUseData>  $dayUses
     * @param  list<BlockData>  $blocks
     * @param  list<int>  $weekendDays
     */
    public function __construct(
        public PropertySummaryData $property,
        public StayViewWindowData $window,
        public array $rooms,
        public array $stays,
        public array $dayUses,
        public array $blocks,
        public array $weekendDays,
        public bool $canViewReservations,
    ) {}
}
