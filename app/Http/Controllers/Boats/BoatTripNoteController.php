<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\SetBoatTripNote;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\UnitBoatTrip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Note field of a Boat Trips row (`boats.trips.note`). A trip of another property is a 404.
 */
class BoatTripNoteController extends Controller
{
    public function __invoke(Request $request, Property $property, UnitBoatTrip $trip, SetBoatTripNote $setNote): RedirectResponse
    {
        abort_unless($trip->property_id === $property->id, 404);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $setNote($request->user(), $trip, $validated['note'] ?? null);

        return back();
    }
}
