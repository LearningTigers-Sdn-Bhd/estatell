<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\SaveBoatCrewMember;
use App\Data\Boats\BoatCrewMemberData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatCrewMemberRequest;
use App\Models\BoatCrewMember;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Boats › Crew: the people who run the boats. See docs/24-boats/01-boat-settings.md#crew-tab.
 */
class BoatCrewController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/boats/crew', [
            'crew' => BoatCrewMember::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (BoatCrewMember $crewMember): BoatCrewMemberData => BoatCrewMemberData::fromModel($crewMember))
                ->all(),
        ]);
    }

    public function store(BoatCrewMemberRequest $request, Property $property, SaveBoatCrewMember $saveBoatCrewMember): RedirectResponse
    {
        $crewMember = $saveBoatCrewMember($request->user(), $property, $request->crewMemberAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $crewMember->name])]);

        return back();
    }

    /**
     * The Available switch sends the full row with a new `active`, so the toast names the change. Other saves say "saved".
     */
    public function update(BoatCrewMemberRequest $request, Property $property, BoatCrewMember $crewMember, SaveBoatCrewMember $saveBoatCrewMember): RedirectResponse
    {
        $wasActive = $crewMember->active;

        $crewMember = $saveBoatCrewMember($request->user(), $property, $request->crewMemberAttributes(), $crewMember);

        $message = match (true) {
            $crewMember->active && ! $wasActive => __(':name is available.', ['name' => $crewMember->name]),
            ! $crewMember->active && $wasActive => __(':name is unavailable.', ['name' => $crewMember->name]),
            default => __(':name saved.', ['name' => $crewMember->name]),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
