<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Guests\UpdateGuest;
use App\Actions\Reservations\AddGuestToUnit;
use App\Actions\Reservations\AddUnit;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AddUnitRequest;
use App\Http\Requests\Reservations\AttachGuestRequest;
use App\Http\Requests\Reservations\UpdateUnitGuestRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRefusals;
use App\Services\Reservations\RoutingChangeNotice;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationUnitController extends Controller
{
    /**
     * Add one unit. A refusal goes back to the Sheet as an error on the matching field. A second unit that starts sending room charges to the Master folio saves with a warning toast when a unit's own folio keeps room lines or an advance.
     */
    public function store(AddUnitRequest $request, Property $property, Reservation $reservation, AddUnit $addUnit, ReservationRefusals $refusals, RoutingChangeNotice $notice): RedirectResponse
    {
        $result = $addUnit($request->user(), $reservation, $request->toUnitData());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors(
                $refusals->errors((string) $result->refusal, $result->date, $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        Inertia::flash('toast', $notice->toast(__('Unit added.'), $reservation, $result->unitsWithPostedRoomLines, $result->unitAdvances));

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Put a guest on a unit: an existing guest (with a profile update when asked) or a new guest. A refusal goes back to
     * the draft row as an inline error and writes nothing.
     */
    public function storeGuest(AttachGuestRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, AddGuestToUnit $addGuest, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $addGuest($request->user(), $property, $unit, $request->filled('guest_id') ? $request->integer('guest_id') : null, $request->profile(), $request->boolean('update_profile'));

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors($refusals->errors((string) $result->refusal));
        }

        $name = $result->unitGuest?->guest?->full_name;

        Inertia::flash('toast', $result->taxWarning !== null
            ? ['type' => 'warning', 'message' => $result->taxWarning]
            : ['type' => 'success', 'message' => __(':name added to the unit.', ['name' => $name])]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Save the profile of a guest who is already on the unit. The unit link does not change.
     */
    public function updateGuest(UpdateUnitGuestRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, int $guestId, UpdateGuest $updateGuest): RedirectResponse
    {
        $guest = $unit->guests()->where('guest_id', $guestId)->with('guest')->firstOrFail()->guest;

        $saved = $updateGuest($request->user(), $guest, $request->profile(), $guest->tags()->pluck('name')->all());

        Inertia::flash('toast', $saved
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->input('full_name')])]
            : ['type' => 'error', 'message' => __(':name cannot be edited.', ['name' => $guest->full_name])]);

        return back(fallback: $this->page($property, $reservation));
    }

    private function page(Property $property, Reservation $reservation): string
    {
        return route('reservations.show', ['property' => $property, 'reservation' => $reservation]);
    }
}
