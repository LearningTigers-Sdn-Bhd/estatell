<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\UpdateReservationGroup;
use App\Enums\BillingMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\UpdateReservationGroupRequest;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationGroupController extends Controller
{
    /**
     * Change the group name and billing mode. It works on closed reservations too.
     */
    public function update(UpdateReservationGroupRequest $request, Property $property, Reservation $reservation, UpdateReservationGroup $updateReservationGroup): RedirectResponse
    {
        $updateReservationGroup($request->user(), $reservation, $request->validated('group_name'), $request->enum('billing_mode', BillingMode::class));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group details saved.')]);

        return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]));
    }
}
