<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\DoorLocks\RemoveDoorCard;
use App\Actions\Kiosk\RegisterStayCard;
use App\Http\Controllers\Controller;
use App\Models\DoorCard;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReservationDoorCardController extends Controller
{
    /**
     * Register the card staff scanned on the desk reader with every lock of the stay, until departure. Nothing is written to the card.
     */
    public function store(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, RegisterStayCard $register): RedirectResponse
    {
        $uid = $request->validate(['uid' => ['required', 'string', 'max:32']])['uid'];
        $result = $register($unit->load('property.settings', 'property.doorLockConnection'), $uid);

        Inertia::flash('toast', match (true) {
            $result->refusal === null => ['type' => 'success', 'message' => __('Card added. It opens the door until :time.', ['time' => $result->validUntil->setTimezone($unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur')->format('d M H:i')])],
            $result->refusal === 'card_unknown' => ['type' => 'error', 'message' => __('The reader gave a card number the lock does not accept. Scan the card again.')],
            $result->refusal === 'card_limit' => ['type' => 'error', 'message' => __('This stay already has 4 cards on a lock. Remove one first.')],
            $result->refusal === 'card_in_use' => ['type' => 'error', 'message' => __('This card is already registered for another stay on the lock. Remove it there first.')],
            $result->refusal === 'gateway_required' => ['type' => 'error', 'message' => __('The lock has no gateway, so cards cannot be added from here.')],
            default => ['type' => 'error', 'message' => __('The lock did not take the card. Check the guest is checked in, the gateway is online, and the card is not on another stay.')],
        });

        return back();
    }

    /**
     * Take a card off the stay's lock, for a lost card or a guest who left early. Without a gateway the lock still accepts it until it ends, and the toast says so.
     */
    public function destroy(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, DoorCard $doorCard, RemoveDoorCard $remove): RedirectResponse
    {
        $result = $remove($doorCard->load('doorLock.property.doorLockConnection'), $request->user());

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $result->message ?? __('The card could not be removed.')]
            : ['type' => $result->warning === null ? 'success' : 'warning', 'message' => trim(__('Card removed.').' '.$result->warning)]);

        return back();
    }
}
