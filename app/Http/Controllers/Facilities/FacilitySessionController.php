<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\DeleteFacilitySession;
use App\Actions\Facilities\SaveFacilitySession;
use App\Data\Facilities\FacilitySessionData;
use App\Enums\FacilitySharing;
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
 * Settings › Facilities › {facility} › Sessions: the fixed times of a Shared facility.
 * See docs/33-facilities/02-facility-settings.md#sessions.
 */
class FacilitySessionController extends Controller
{
    /**
     * Only a Shared facility has sessions, so a Private one answers 404.
     */
    public function index(Property $property, Facility $facility): Response
    {
        abort_unless($facility->sharing === FacilitySharing::Shared, 404);

        return Inertia::render('settings/facilities/sessions', [
            'facility' => ['id' => $facility->id, 'name' => $facility->name],
            'sessions' => $facility->sessions()
                ->ordered()
                ->get()
                ->map(fn (FacilitySession $session): FacilitySessionData => FacilitySessionData::fromModel($session))
                ->all(),
        ]);
    }

    public function store(FacilitySessionRequest $request, Property $property, Facility $facility, SaveFacilitySession $saveSession): RedirectResponse
    {
        return $this->saved($property, $facility, $saveSession($request->user(), $facility, $request->sessionAttributes()), __('Session added.'));
    }

    public function update(FacilitySessionRequest $request, Property $property, Facility $facility, FacilitySession $session, SaveFacilitySession $saveSession): RedirectResponse
    {
        return $this->saved($property, $facility, $saveSession($request->user(), $facility, $request->sessionAttributes(), $session), __('Session saved.'));
    }

    public function destroy(Request $request, Property $property, Facility $facility, FacilitySession $session, DeleteFacilitySession $deleteSession): RedirectResponse
    {
        $deleteSession($request->user(), $session);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session deleted.')]);

        return to_route('settings.facilities.sessions.index', [$property, $facility]);
    }

    /**
     * A null session is the `facility_not_shared` refusal: the facility became Private in the meantime.
     */
    private function saved(Property $property, Facility $facility, ?FacilitySession $session, string $message): RedirectResponse
    {
        if ($session === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Only a Shared facility has sessions.')]);

            return to_route('settings.facilities.index', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('settings.facilities.sessions.index', [$property, $facility]);
    }
}
