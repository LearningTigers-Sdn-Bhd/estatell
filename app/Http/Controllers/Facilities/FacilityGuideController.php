<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\SaveFacilityGuide;
use App\Data\Facilities\FacilityGuideData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityGuideRequest;
use App\Models\FacilityGuide;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Facilities › Guides: picked for each activity session on the Activities run sheet.
 * See docs/33-facilities/06-guides-and-boats.md#settings.
 */
class FacilityGuideController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/facilities/guides', [
            'guides' => FacilityGuide::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (FacilityGuide $item): FacilityGuideData => FacilityGuideData::fromModel($item))
                ->all(),
        ]);
    }

    public function store(FacilityGuideRequest $request, Property $property, SaveFacilityGuide $save): RedirectResponse
    {
        $save($request->user(), $property, $request->guideAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guide added.')]);

        return back();
    }

    public function update(FacilityGuideRequest $request, Property $property, FacilityGuide $facilityGuide, SaveFacilityGuide $save): RedirectResponse
    {
        $wasActive = $facilityGuide->active;
        $item = $save($request->user(), $property, $request->guideAttributes(), $facilityGuide);

        $message = match (true) {
            $item->active && ! $wasActive => __(':name is available.', ['name' => $item->name]),
            ! $item->active && $wasActive => __(':name is unavailable.', ['name' => $item->name]),
            default => __('Guide saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
