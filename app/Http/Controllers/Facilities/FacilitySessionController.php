<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\DeleteFacilitySession;
use App\Actions\Facilities\SaveFacilitySession;
use App\Data\Facilities\FacilityData;
use App\Data\Facilities\FacilitySessionData;
use App\Data\Property\CurrencyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilitySessionRequest;
use App\Models\Facility;
use App\Models\FacilitySession;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Facilities › {facility} › Sessions: the fixed times of a facility.
 * See docs/33-facilities/02-facility-settings.md#sessions.
 */
class FacilitySessionController extends Controller
{
    /**
     * The facility page's Sessions tab.
     */
    public function index(Property $property, Facility $facility): Response
    {
        return Inertia::render('settings/facilities/sessions', [
            'facility' => FacilityData::fromModel($facility->load('chargeItem.transactionCode.applicableTaxes')->loadCount('sessions')),
            'sessions' => $facility->sessions()
                ->ordered()
                ->get()
                ->map(fn (FacilitySession $session): FacilitySessionData => FacilitySessionData::fromModel($session))
                ->all(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function store(FacilitySessionRequest $request, Property $property, Facility $facility, SaveFacilitySession $saveSession): RedirectResponse
    {
        $saveSession($request->user(), $facility, $request->sessionAttributes());

        return $this->saved($property, $facility, __('Session added.'));
    }

    public function update(FacilitySessionRequest $request, Property $property, Facility $facility, FacilitySession $session, SaveFacilitySession $saveSession): RedirectResponse
    {
        $saveSession($request->user(), $facility, $request->sessionAttributes(), $session);

        return $this->saved($property, $facility, __('Session saved.'));
    }

    public function destroy(Request $request, Property $property, Facility $facility, FacilitySession $session, DeleteFacilitySession $deleteSession): RedirectResponse
    {
        $deleteSession($request->user(), $session);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session deleted.')]);

        return to_route('settings.facilities.sessions.index', [$property, $facility]);
    }

    private function saved(Property $property, Facility $facility, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('settings.facilities.sessions.index', [$property, $facility]);
    }
}
