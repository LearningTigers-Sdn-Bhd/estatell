<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\AddFacilitySessions;
use App\Actions\Facilities\DeleteFacilitySession;
use App\Actions\Facilities\SaveFacilitySession;
use App\Data\Facilities\FacilityData;
use App\Data\Facilities\FacilitySessionData;
use App\Data\Property\CurrencyData;
use App\Enums\FacilitySessionLength;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\AddFacilitySessionsRequest;
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
            'sessionLengths' => FacilitySessionLength::options(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * Adds one session, or every session of a range at once. Sessions the facility already has are skipped.
     */
    public function store(AddFacilitySessionsRequest $request, Property $property, Facility $facility, AddFacilitySessions $addSessions): RedirectResponse
    {
        $added = $addSessions($request->user(), $facility, (string) $request->validated('starts_at'), (string) $request->validated('ends_at'), $request->length());

        return $this->saved($property, $facility, count($added) === 1 ? __('Session added.') : __(':count sessions added.', ['count' => count($added)]));
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
