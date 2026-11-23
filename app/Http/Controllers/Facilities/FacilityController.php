<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\CreateFacility;
use App\Actions\Facilities\ReorderFacilities;
use App\Actions\Facilities\UpdateFacility;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Facilities\FacilityData;
use App\Data\Property\CurrencyData;
use App\Enums\FacilityBookedBy;
use App\Enums\FacilityRateBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityRequest;
use App\Http\Requests\Facilities\ReorderFacilitiesRequest;
use App\Models\Facility;
use App\Models\Property;
use App\Models\Tax;
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
                ->with('chargeItem.transactionCode.applicableTaxes')
                ->withCount('sessions')
                ->ordered()
                ->get()
                ->map(fn (Facility $facility): FacilityData => FacilityData::fromModel($facility))
                ->all(),
            'bookedByOptions' => FacilityBookedBy::options(),
            'rateBases' => FacilityRateBasis::optionsByBookedBy(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * The facility page's Details tab: every field of the facility, its taxes, and a summary beside them.
     */
    public function show(Property $property, Facility $facility): Response
    {
        abort_if($property->settings === null, 404);

        return Inertia::render('settings/facilities/show', [
            'facility' => FacilityData::fromModel($facility->load('chargeItem.transactionCode.applicableTaxes')->loadCount('sessions')),
            'bookedByOptions' => FacilityBookedBy::options(),
            'rateBases' => FacilityRateBasis::optionsByBookedBy(),
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax))
                ->all(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * A new facility opens on its own page, where staff add taxes and sessions.
     */
    public function store(FacilityRequest $request, Property $property, CreateFacility $createFacility): RedirectResponse
    {
        $facility = $createFacility($property, $request->user(), $request->facilityAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Facility added.')]);

        return to_route('settings.facilities.show', [$property, $facility]);
    }

    /**
     * The Available switches send the full row with a new `active`, so the toast names the change. Saves go back to
     * the page they came from: the list or the Details tab.
     */
    public function update(FacilityRequest $request, Property $property, Facility $facility, UpdateFacility $updateFacility): RedirectResponse
    {
        $wasActive = $facility->active;

        $saved = $updateFacility($request->user(), $facility, $request->facilityAttributes());

        if ($saved === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Cancel or move its upcoming bookings first, then change Booked by.')]);

            return back();
        }

        $facility = $saved;

        $message = match (true) {
            $facility->active && ! $wasActive => __(':name is on.', ['name' => $facility->name]),
            ! $facility->active && $wasActive => __(':name is off.', ['name' => $facility->name]),
            default => __('Facility saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function reorder(ReorderFacilitiesRequest $request, Property $property, ReorderFacilities $reorderFacilities): RedirectResponse
    {
        if (! $reorderFacilities($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The facilities changed. Reload the page and try again.')]);
        }

        return to_route('settings.facilities.index', $property);
    }
}
