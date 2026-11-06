<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\DoorLocks\CreateKeyportJob;
use App\Enums\KeyportJobType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Keyport\StoreKeyCardRequest;
use App\Models\KeyportJob;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationKeyCardController extends Controller
{
    /**
     * Ask the desk PC to make, rewrite or erase the stay's key card. A refusal is an error toast. Nothing is written until a person
     * puts a card on the reader.
     */
    public function store(StoreKeyCardRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, CreateKeyportJob $createKeyportJob): RedirectResponse
    {
        $lock = $property->doorLocks()->findOrFail($request->integer('door_lock_id'));
        $result = $createKeyportJob($request->user(), $unit, $lock, KeyportJobType::from((string) $request->input('type')), $request->filled('key') ? (string) $request->input('key') : null);

        Inertia::flash('toast', $result instanceof KeyportJob
            ? ['type' => 'success', 'message' => __('Waiting for the card. Put a blank card on the reader at the desk.')]
            : ['type' => 'error', 'message' => $result]);

        return back();
    }
}
