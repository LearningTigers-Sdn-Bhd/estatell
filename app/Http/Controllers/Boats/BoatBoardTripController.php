<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\MoveBoatTrips;
use App\Actions\Boats\SetBoatTripTick;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatBoardTripsRequest;
use App\Models\Property;
use App\Models\UnitBoatTrip;
use App\Services\Boats\SailingRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * The ticked rows of the Boats board: Move to, and the Arrived or Left tick. A trip of another property is a 404. See
 * docs/24-boats/05-boats-board.md#bulk-bar.
 */
class BoatBoardTripController extends Controller
{
    public function move(BoatBoardTripsRequest $request, Property $property, MoveBoatTrips $moveBoatTrips, SailingRefusals $refusals): RedirectResponse
    {
        $ids = $request->tripIds();
        $this->trips($property, $ids);

        $result = $moveBoatTrips(
            $request->user(),
            $property,
            $ids,
            $request->validated('boat_sailing_id') === null ? null : (int) $request->validated('boat_sailing_id'),
            $request->validated('boat_departure_id') === null ? null : (int) $request->validated('boat_departure_id'),
        );

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message($result)]
            : ['type' => 'success', 'message' => trans_choice('Moved :count room.|Moved :count rooms.', count($ids))]);

        return back();
    }

    /**
     * Ticks the trips (or un-ticks them with `ticked` false). A trip already in the wanted state is skipped.
     */
    public function tick(BoatBoardTripsRequest $request, Property $property, SetBoatTripTick $setTick): RedirectResponse
    {
        $ticked = $request->boolean('ticked', true);
        $changed = 0;

        foreach ($this->trips($property, $request->tripIds()) as $trip) {
            if (($trip->ticked_at !== null) === $ticked) {
                continue;
            }

            $setTick($request->user(), $trip, $ticked);
            $changed++;
        }

        if (count($request->tripIds()) > 1 || $changed === 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $changed === 0
                ? __('Nothing to change.')
                : trans_choice(':count room updated.|:count rooms updated.', $changed)]);
        }

        return back();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, UnitBoatTrip>
     */
    private function trips(Property $property, array $ids): Collection
    {
        $trips = UnitBoatTrip::query()->where('property_id', $property->id)->whereKey($ids)->orderBy('id')->get();

        abort_unless($trips->count() === count($ids), 404);

        return $trips;
    }
}
