<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\AssignFacilitySlotCrew;
use App\Data\Facilities\FacilityBoatData;
use App\Data\Facilities\FacilityGuideData;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\AssignFacilitySlotCrewRequest;
use App\Models\Facility;
use App\Models\FacilityBoat;
use App\Models\FacilityGuide;
use App\Models\Property;
use App\Services\Facilities\ActivityRunSheet;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Front desk › Activities: every activity session of one date with its places taken, and the guide and boat of each.
 * See docs/33-facilities/06-guides-and-boats.md#activities-run-sheet.
 */
class ActivityRunSheetController extends Controller
{
    public function index(Request $request, Property $property, ActivityRunSheet $runSheet, CurrentBusinessDate $businessDate, PropertyToday $today): Response
    {
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $businessDay = $businessDate->date($property) ?? $today->date($property);
        $date = isset($validated['date']) ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay() : $businessDay;

        return Inertia::render('activities/index', [
            'date' => $date->toDateString(),
            'today' => $businessDay->toDateString(),
            'slots' => $runSheet->slots($property, $date),
            'canAssign' => $request->user()->can(PermissionEnum::FacilitiesBook->value),
            'canViewList' => $request->user()->can(PermissionEnum::ReportsView->value),
            'guides' => FacilityGuide::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (FacilityGuide $guide): FacilityGuideData => FacilityGuideData::fromModel($guide))
                ->all(),
            'boats' => FacilityBoat::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (FacilityBoat $boat): FacilityBoatData => FacilityBoatData::fromModel($boat))
                ->all(),
        ]);
    }

    public function assign(AssignFacilitySlotCrewRequest $request, Property $property, Facility $facility, AssignFacilitySlotCrew $assign): RedirectResponse
    {
        $refusal = $assign(
            $request->user(),
            $facility,
            $request->slotDate(),
            (string) $request->validated('starts_at'),
            (string) $request->validated('ends_at'),
            $request->guideId(),
            $request->boatId(),
        );

        if ($refusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => match ($refusal) {
                AssignFacilitySlotCrew::REFUSAL_GUIDE_NOT_FOUND => __('That guide is not available. Pick another.'),
                AssignFacilitySlotCrew::REFUSAL_BOAT_NOT_FOUND => __('That boat is not available. Pick another.'),
                default => __('This session is no longer on the run sheet. Reload the page.'),
            }]);
        }

        return back();
    }
}
