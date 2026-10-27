<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\FrontDesk\AutoAssignBookedUnit;
use App\Actions\Guests\UpdateGuest;
use App\Actions\Reservations\AddGuestToUnit;
use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\DetachGuest;
use App\Actions\Reservations\MoveGuestToUnit;
use App\Actions\Reservations\ReplaceUnitGuest;
use App\Data\Reservations\NewAddOnData;
use App\Enums\PermissionEnum;
use App\Enums\StayType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AddUnitRequest;
use App\Http\Requests\Reservations\AttachGuestRequest;
use App\Http\Requests\Reservations\MoveUnitGuestRequest;
use App\Http\Requests\Reservations\UpdateUnitGuestRequest;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Boats\CopyableBoatTrips;
use App\Services\Reservations\AutoAddOns;
use App\Services\Reservations\ReservationRefusals;
use App\Services\Reservations\RoutingChangeNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class ReservationUnitController extends Controller
{
    /**
     * Add one unit. A refusal goes back to the Sheet as an error on the matching field. A second unit that starts sending room charges to the Master folio saves with a warning toast when a unit's own folio keeps room lines or an advance.
     */
    public function store(AddUnitRequest $request, Property $property, Reservation $reservation, AddUnit $addUnit, ReservationRefusals $refusals, RoutingChangeNotice $notice, AutoAddOns $autoAddOns, CopyableBoatTrips $copyableBoatTrips, AutoAssignBookedUnit $autoAssignBookedUnit): RedirectResponse
    {
        $data = $request->toUnitData();
        $autoItems = $autoAddOns->items($property, $data->stayType);
        $data->addOns = array_map(fn (ChargeItem $item): NewAddOnData => new NewAddOnData($item->id), $autoItems);
        $boatTrips = $data->stayType === StayType::Overnight ? $copyableBoatTrips->forNewUnit($reservation, $property) : ['trips' => [], 'skipped' => []];
        $data->boatTrips = $boatTrips['trips'];

        $result = $addUnit($request->user(), $reservation, $data);

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors(
                $refusals->errors((string) $result->refusal, $result->date, $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        if ($result->unit !== null) {
            $autoAssignBookedUnit($request->user(), $result->unit->refresh());
        }

        $message = $autoItems === []
            ? __('Unit added.')
            : __('Unit added with :items.', ['items' => implode(', ', array_map(fn (ChargeItem $item): string => $item->name, $autoItems))]);

        foreach ($boatTrips['skipped'] as $skipped) {
            $message .= ' '.__(':boat was skipped. It is no longer available.', ['boat' => $skipped]);
        }

        Inertia::flash('toast', $notice->toast($message, $reservation, $result->unitsWithPostedRoomLines, $result->unitAdvances));

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

        $saved = $updateGuest($request->user(), $guest, $request->profile(), array_values($guest->tags()->pluck('name')->all()));

        Inertia::flash('toast', $saved
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->input('full_name')])]
            : ['type' => 'error', 'message' => __(':name cannot be edited.', ['name' => $guest->full_name])]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Put another person in place of a guest on the unit. A refusal goes back to the row as an inline error and writes
     * nothing.
     */
    public function replaceGuest(AttachGuestRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, int $guestId, ReplaceUnitGuest $replaceGuest, ReservationRefusals $refusals): RedirectResponse
    {
        $occupant = $unit->guests()->where('guest_id', $guestId)->with('guest')->firstOrFail();
        $result = $replaceGuest($request->user(), $property, $unit, $occupant, $request->filled('guest_id') ? $request->integer('guest_id') : null, $request->profile(), $request->boolean('update_profile'));

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors($refusals->errors((string) $result->refusal));
        }

        $name = $result->unitGuest?->guest?->full_name;

        Inertia::flash('toast', $result->taxWarning !== null
            ? ['type' => 'warning', 'message' => $result->taxWarning]
            : ['type' => 'success', 'message' => __(':name is now on the unit.', ['name' => $name])]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Take a guest off the unit. A refusal is an error toast and nothing is written.
     */
    public function destroyGuest(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, int $guestId, DetachGuest $detachGuest, ReservationRefusals $refusals): RedirectResponse
    {
        $occupant = $unit->guests()->where('guest_id', $guestId)->with('guest')->firstOrFail();
        $name = $occupant->guest->full_name;
        $refusal = $detachGuest($request->user(), $unit, $occupant);

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __(':name removed from the unit.', ['name' => $name])]
            : ['type' => 'error', 'message' => (string) Arr::first($refusals->errors($refusal))]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Move a guest to another unit of the reservation. A refusal goes back to the Dialog as an inline error and writes
     * nothing.
     */
    public function moveGuest(MoveUnitGuestRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, int $guestId, MoveGuestToUnit $moveGuest, ReservationRefusals $refusals): RedirectResponse
    {
        $occupant = $unit->guests()->where('guest_id', $guestId)->with('guest')->firstOrFail();
        $target = $reservation->units()->findOrFail($request->integer('target_unit_id'));
        $result = $moveGuest($request->user(), $unit, $occupant, $target);

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', $result->taxWarning !== null
            ? ['type' => 'warning', 'message' => $result->taxWarning]
            : ['type' => 'success', 'message' => __(':name moved to :unit.', ['name' => $occupant->guest->full_name, 'unit' => $target->roomType->name])]);

        return back(fallback: $this->page($property, $reservation));
    }

    private function page(Property $property, Reservation $reservation): string
    {
        return route('reservations.show', ['property' => $property, 'reservation' => $reservation]);
    }
}
