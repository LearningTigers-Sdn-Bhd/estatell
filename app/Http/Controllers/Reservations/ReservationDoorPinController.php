<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\DoorLocks\ReplaceStayPasscode;
use App\Http\Controllers\Controller;
use App\Models\DoorPasscode;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReservationDoorPinController extends Controller
{
    /**
     * Give the stay a PIN staff typed, in place of its current one. A refusal is an error toast, a warning shows with the success.
     */
    public function replace(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, DoorPasscode $doorPasscode, ReplaceStayPasscode $replace): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'regex:/^\d{4,9}$/']], ['code.regex' => __('A PIN is 4 to 9 digits.')])['code'];
        $result = $replace($doorPasscode->load('doorLock.property.doorLockConnection', 'reservationUnit.property.settings'), $code, $request->user());

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $result->message]
            : ['type' => $result->warning === null ? 'success' : 'warning', 'message' => trim(__('PIN set.').' '.$result->warning)]);

        return back();
    }
}
