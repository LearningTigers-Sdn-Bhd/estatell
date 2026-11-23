<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\CreateFacility;
use App\Actions\Facilities\ReorderFacilities;
use App\Actions\Facilities\UpdateFacility;
use App\Data\Facilities\FacilityData;
use App\Data\Property\CurrencyData;
use App\Enums\FacilityCapacityUnit;
use App\Enums\FacilityRateBasis;
use App\Enums\FacilitySharing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityRequest;
use App\Http\Requests\Facilities\ReorderFacilitiesRequest;
use App\Models\Facility;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Facilities: the halls, equipment and activities staff can book.
 * See docs/33-facilities/02-facility-settings.md.
 */
class FacilityController extends Controller
{
    public function index(Property $property): Response
    {
        abort_if($property->settings === null, 404);

        return Inertia::render('settings/facilities/index', [
            'facilities' => $property->facilities()
                ->with('chargeItem')
                ->withCount('sessions')
                ->ordered()
                ->get()
                ->map(fn (Facility $facility): FacilityData => FacilityData::fromModel($facility))
                ->all(),
            'sharingOptions' => FacilitySharing::options(),
            'capacityUnits' => FacilityCapacityUnit::options(),
            'rateBases' => FacilityRateBasis::options(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function store(FacilityRequest $request, Property $property, CreateFacility $createFacility): RedirectResponse
    {
        $createFacility($property, $request->user(), $request->facilityAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Facility added.')]);

        return to_route('settings.facilities.index', $property);
    }

    /**
     * Turn off and Turn on send the full row with a new `active`, so the toast names the change.
     */
    public function update(FacilityRequest $request, Property $property, Facility $facility, UpdateFacility $updateFacility): RedirectResponse
    {
        $wasActive = $facility->active;

        $facility = $updateFacility($request->user(), $facility, $request->facilityAttributes());

        $message = match (true) {
            $facility->active && ! $wasActive => __(':name is on.', ['name' => $facility->name]),
            ! $facility->active && $wasActive => __(':name is off.', ['name' => $facility->name]),
            default => __('Facility saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('settings.facilities.index', $property);
    }

    public function reorder(ReorderFacilitiesRequest $request, Property $property, ReorderFacilities $reorderFacilities): RedirectResponse
    {
        if (! $reorderFacilities($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The facilities changed. Reload the page and try again.')]);
        }

        return to_route('settings.facilities.index', $property);
    }
}
