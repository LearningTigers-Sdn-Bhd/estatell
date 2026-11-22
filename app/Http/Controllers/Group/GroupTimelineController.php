<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Http\Requests\FrontDesk\StayViewTimelineRequest;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupTimeline;
use App\Services\Property\PropertyToday;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

class GroupTimelineController extends Controller
{
    /**
     * The group timeline: every property's Timeline View for the same dates (`from`, `to`, 30 days at most, 14 from
     * today by default), read only. See docs/03-staff/05-group-mode.md#group-timeline.
     */
    public function __invoke(StayViewTimelineRequest $request, PropertyGroup $propertyGroup, GroupTimeline $timeline, PropertyToday $propertyToday): Response
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);
        [$from, $to] = $request->window($propertyToday->date($properties->firstOrFail()));

        return Inertia::render('group/timeline', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'properties' => $timeline->for($request->user(), $properties, $from, $to),
        ]);
    }
}
