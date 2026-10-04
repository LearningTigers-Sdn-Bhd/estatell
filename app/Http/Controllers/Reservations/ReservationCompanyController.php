<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\UpdateReservationCompany;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\UpdateReservationCompanyRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationCompanyController extends Controller
{
    /**
     * Set or clear the billed company. A refusal goes back to the Dialog as an error on the company field.
     */
    public function update(UpdateReservationCompanyRequest $request, Property $property, Reservation $reservation, UpdateReservationCompany $updateReservationCompany, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $updateReservationCompany($request->user(), $reservation, $request->filled('company_id') ? $request->integer('company_id') : null, $request->user()->can(PermissionEnum::CompanyCreditOverride->value) ? $request->input('credit_override_reason') : null);

        if ($result->isRefused()) {
            return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]))
                ->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company saved.')]);

        return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]));
    }
}
