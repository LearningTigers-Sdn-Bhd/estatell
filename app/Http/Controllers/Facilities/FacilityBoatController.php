<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\SaveFacilityBoat;
use App\Data\Facilities\FacilityBoatData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityBoatRequest;
use App\Models\FacilityBoat;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Facilities › Boats: picked for each activity session on the Activities run sheet.
 * See docs/33-facilities/06-guides-and-boats.md#settings.
 */
class FacilityBoatController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/facilities/boats', [
            'boats' => FacilityBoat::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (FacilityBoat $item): FacilityBoatData => FacilityBoatData::fromModel($item))
                ->all(),
        ]);
    }

    public function store(FacilityBoatRequest $request, Property $property, SaveFacilityBoat $save): RedirectResponse
    {
        $save($request->user(), $property, $request->boatAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat added.')]);

        return back();
    }

    public function update(FacilityBoatRequest $request, Property $property, FacilityBoat $facilityBoat, SaveFacilityBoat $save): RedirectResponse
    {
        $wasActive = $facilityBoat->active;
        $item = $save($request->user(), $property, $request->boatAttributes(), $facilityBoat);

        $message = match (true) {
            $item->active && ! $wasActive => __(':name is available.', ['name' => $item->name]),
            ! $item->active && $wasActive => __(':name is unavailable.', ['name' => $item->name]),
            default => __('Boat saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
