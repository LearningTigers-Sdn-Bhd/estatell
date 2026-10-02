<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\AttachGuest;
use App\Actions\Reservations\AttachGuestResult;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AddUnitRequest;
use App\Http\Requests\Reservations\AttachGuestRequest;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationUnitController extends Controller
{
    /**
     * Add one unit. A refusal goes back to the Sheet as an error on the matching field.
     */
    public function store(AddUnitRequest $request, Property $property, Reservation $reservation, AddUnit $addUnit, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $addUnit($request->user(), $reservation, $request->toUnitData());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors(
                $refusals->errors((string) $result->refusal, $result->date, $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unit added.')]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Attach an existing guest to a unit.
     */
    public function storeGuest(AttachGuestRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, AttachGuest $attachGuest, ReservationRefusals $refusals): RedirectResponse
    {
        $guest = Guest::query()->where('property_id', $property->id)->find($request->integer('guest_id'));
        $result = $guest === null
            ? AttachGuestResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND)
            : $attachGuest($request->user(), $unit, $guest);

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added to the unit.', ['name' => $guest?->full_name])]);

        return back(fallback: $this->page($property, $reservation));
    }

    private function page(Property $property, Reservation $reservation): string
    {
        return route('reservations.show', ['property' => $property, 'reservation' => $reservation]);
    }
}
