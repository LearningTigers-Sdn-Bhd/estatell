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
     * The group timeline: every property's Timeline View for the same dates (`from`, `to`, 31 days at most; today and
     * the next 13 days by default, the 2 weeks view), read only. See docs/03-staff/05-group-mode.md#group-timeline.
     */
    public function __invoke(StayViewTimelineRequest $request, PropertyGroup $propertyGroup, GroupTimeline $timeline, PropertyToday $propertyToday): Response
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);
        $today = $propertyToday->date($properties->firstOrFail());
        // With no dates the group timeline opens on today and the next two weeks, wide enough to read the names
        // (decided 2026-11-26; it opened on the whole month before). Month stays one click away.
        [$from, $to] = $request->filled('from') || $request->filled('to')
            ? $request->window($today)
            : [$today, $today->addDays(13)];

        return Inertia::render('group/timeline', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $today->toDateString(),
            'properties' => $timeline->for($request->user(), $properties, $from, $to),
        ]);
    }
}
