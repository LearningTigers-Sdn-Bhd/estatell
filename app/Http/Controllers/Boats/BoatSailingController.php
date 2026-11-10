<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\AddSailingBoat;
use App\Actions\Boats\RemoveSailing;
use App\Actions\Boats\ResetDepartureDate;
use App\Actions\Boats\SailingResult;
use App\Actions\Boats\SaveSailing;
use App\Enums\SailingKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatDepartureDateRequest;
use App\Http\Requests\Boats\SaveBoatSailingRequest;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Services\Boats\SailingRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The group menu of the Boats board: change the boat and crew of a sailing, add a boat, remove an added boat and go back
 * to the timetable. A departure or sailing of another property is a 404. A refusal is an error toast. See
 * docs/24-boats/05-boats-board.md#group-menu.
 */
class BoatSailingController extends Controller
{
    public function save(SaveBoatSailingRequest $request, Property $property, SaveSailing $saveSailing, SailingRefusals $refusals): RedirectResponse
    {
        $departure = $this->departure($property, (int) $request->validated('boat_departure_id'));
        $sailing = $request->validated('boat_sailing_id') === null
            ? null
            : BoatSailing::query()->where('property_id', $property->id)->where('kind', SailingKind::Guests->value)->where('boat_departure_id', $departure->id)->findOrFail((int) $request->validated('boat_sailing_id'));

        return $this->respond($saveSailing($request->user(), $departure, $request->sailingDate(), $request->sailingData(), $sailing), __('Boat and crew saved.'), $refusals);
    }

    public function store(SaveBoatSailingRequest $request, Property $property, AddSailingBoat $addSailingBoat, SailingRefusals $refusals): RedirectResponse
    {
        $departure = $this->departure($property, (int) $request->validated('boat_departure_id'));

        return $this->respond($addSailingBoat($request->user(), $departure, $request->sailingDate(), $request->sailingData()), __('Boat added.'), $refusals);
    }

    public function destroy(Request $request, Property $property, BoatSailing $sailing, RemoveSailing $removeSailing, SailingRefusals $refusals): RedirectResponse
    {
        abort_unless($sailing->property_id === $property->id && $sailing->kind === SailingKind::Guests && $sailing->boat_departure_id !== null, 404);

        return $this->respond($removeSailing($request->user(), $sailing), __('Boat removed.'), $refusals);
    }

    public function reset(BoatDepartureDateRequest $request, Property $property, BoatDeparture $departure, ResetDepartureDate $resetDate, SailingRefusals $refusals): RedirectResponse
    {
        abort_unless($departure->property_id === $property->id, 404);

        return $this->respond($resetDate($request->user(), $departure, $request->sailingDate()), __('Back to the timetable.'), $refusals);
    }

    private function departure(Property $property, int $id): BoatDeparture
    {
        return BoatDeparture::query()->where('property_id', $property->id)->findOrFail($id);
    }

    private function respond(SailingResult $result, string $success, SailingRefusals $refusals): RedirectResponse
    {
        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message($result)]
            : ['type' => 'success', 'message' => $success]);

        return back();
    }
}
