<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Boats\SetBoatTripTick;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\UnitBoatTrip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Tick column of the Boat manifest. A trip of another property is a 404.
 */
class BoatTripTickController extends Controller
{
    public function __invoke(Request $request, Property $property, UnitBoatTrip $trip, SetBoatTripTick $setTick): RedirectResponse
    {
        abort_unless($trip->property_id === $property->id, 404);

        $validated = $request->validate(['ticked' => ['required', 'boolean']]);

        $setTick($request->user(), $trip, (bool) $validated['ticked']);

        return back();
    }
}
