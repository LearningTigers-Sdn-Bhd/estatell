<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\UpdateReservationGroup;
use App\Enums\BillingMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\UpdateReservationGroupRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Folio\MasterPaymentsHeld;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationGroupController extends Controller
{
    /**
     * Change the group name and billing mode. It works on closed reservations too. A billing mode change that the Master folio's payments forbid goes back to the Dialog as an error on `billing_mode`.
     */
    public function update(UpdateReservationGroupRequest $request, Property $property, Reservation $reservation, UpdateReservationGroup $updateReservationGroup, ReservationRefusals $refusals, MasterPaymentsHeld $masterPaymentsHeld): RedirectResponse
    {
        $result = $updateReservationGroup($request->user(), $reservation, $request->validated('group_name'), $request->enum('billing_mode', BillingMode::class));

        if ($result->isRefused()) {
            return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]))
                ->withErrors($refusals->groupErrors((string) $result->refusal, $masterPaymentsHeld->label($reservation, (string) $result->amount)));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group details saved.')]);

        return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]));
    }
}
