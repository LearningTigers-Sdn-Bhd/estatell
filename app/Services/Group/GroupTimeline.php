<?php

namespace App\Services\Group;

use App\Data\Group\GroupTimelinePropertyData;
use App\Data\Property\PropertySummaryData;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use App\Services\Staff\GroupProperties;
use App\Services\StayView\StayViewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The group timeline: the Timeline View of every property of the group the user may open and whose role there shows
 * the front desk, for the same dates, one after the other. It is read only: moves, date changes, blocks and new
 * bookings happen in the property's own Timeline View. Its queries grow with the number of properties, never with
 * stays. See docs/03-staff/05-group-mode.md#group-timeline.
 */
class GroupTimeline
{
    public function __construct(
        private GroupProperties $groupProperties,
        private StayViewQuery $query,
    ) {}

    /**
     * @param  Collection<int, Property>  $properties  The properties the user may open in the group.
     * @return list<GroupTimelinePropertyData>
     */
    public function for(User $user, Collection $properties, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $permissions = $this->groupProperties->permissionsFor($user, $properties, [PermissionEnum::FrontDeskView, PermissionEnum::ReservationsView]);
        $blocks = [];

        foreach ($properties as $property) {
            $held = $permissions[$property->id] ?? [];

            if (! in_array(PermissionEnum::FrontDeskView->value, $held, true)) {
                continue;
            }

            $blocks[] = new GroupTimelinePropertyData(
                property: PropertySummaryData::fromModel($property),
                window: $this->query->window($property, $from, $to),
                rooms: $this->query->rows($property, $from),
                stays: $this->query->stays($property, $from, $to),
                dayUses: $this->query->dayUses($property, $from, $to),
                blocks: $this->query->blocks($property, $from, $to),
                weekendDays: $property->settings->weekend_nights->isoWeekdays(),
                canViewReservations: in_array(PermissionEnum::ReservationsView->value, $held, true),
            );
        }

        return $blocks;
    }
}
