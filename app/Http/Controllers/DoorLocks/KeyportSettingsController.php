<?php

namespace App\Http\Controllers\DoorLocks;

use App\Actions\DoorLocks\AddElockLock;
use App\Actions\DoorLocks\CancelKeyportJob;
use App\Actions\DoorLocks\CreateKeyportPairingCode;
use App\Actions\DoorLocks\RemoveElockLock;
use App\Actions\DoorLocks\RevokeKeyportDevice;
use App\Actions\DoorLocks\UpdateElockLock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Keyport\StoreElockLockRequest;
use App\Models\DoorLock;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Keyport actions on the Door locks page (eLock tab): pair or revoke a front-desk PC, add an eLock lock, cancel a card job. See docs/27-door-locks/02-keyport.md.
 */
class KeyportSettingsController extends Controller
{
    /**
     * Key cards now live on the Door locks page. This URL stays so old links and bookmarks still work.
     */
    public function index(Property $property): RedirectResponse
    {
        return to_route('settings.door-locks.edit', $property);
    }

    /**
     * The pairing code is flashed once, not stored in plaintext and not in the URL. The PC names itself when it pairs.
     */
    public function storeCode(Request $request, Property $property, CreateKeyportPairingCode $createKeyportPairingCode): RedirectResponse
    {
        Inertia::flash('pairing', [
            'code' => $createKeyportPairingCode($request->user(), $property),
            'expiresAt' => now()->addMinutes(config()->integer('keyport.pairing_minutes'))->toIso8601String(),
        ]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function revokeDevice(Request $request, Property $property, KeyportDevice $keyportDevice, RevokeKeyportDevice $revokeKeyportDevice): RedirectResponse
    {
        $already = $keyportDevice->revoked_at !== null;
        $revokeKeyportDevice($request->user(), $keyportDevice);

        Inertia::flash('toast', $already
            ? ['type' => 'error', 'message' => __('That computer is already revoked.')]
            : ['type' => 'success', 'message' => __('Computer revoked.')]);

        return to_route('settings.door-locks.edit', $property);
    }

    /**
     * Remove a revoked PC from the list. Only revoked ones: removing a live PC would hide a token that still works. Its jobs keep
     * their history (the job's device link is set to null).
     */
    public function destroyDevice(Request $request, Property $property, KeyportDevice $keyportDevice): RedirectResponse
    {
        if ($keyportDevice->revoked_at === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Revoke the computer first, then remove it.')]);
        } else {
            activity()->performedOn($keyportDevice)->causedBy($request->user())->event('door-locks.keyport-removed')
                ->withProperties(['property_id' => $property->id])->log('Keyport computer removed from the list');
            $keyportDevice->delete();
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Computer removed.')]);
        }

        return to_route('settings.door-locks.edit', $property);
    }

    public function updateLock(StoreElockLockRequest $request, Property $property, DoorLock $doorLock, UpdateElockLock $updateElockLock): RedirectResponse
    {
        $room = $request->filled('room_id') ? Room::query()->where('property_id', $property->id)->find($request->integer('room_id')) : null;
        $updated = ($request->filled('room_id') && $room === null)
            ? false
            : $updateElockLock($request->user(), $doorLock, (string) $request->input('name'), (string) $request->input('lock_no'), $room);

        Inertia::flash('toast', $updated
            ? ['type' => 'success', 'message' => __('Lock saved.')]
            : ['type' => 'error', 'message' => __('That lock number is already used, or the room is not available or already has a lock.')]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function destroyLock(Request $request, Property $property, DoorLock $doorLock, RemoveElockLock $removeElockLock): RedirectResponse
    {
        $reason = $removeElockLock($request->user(), $doorLock);

        Inertia::flash('toast', $reason === null
            ? ['type' => 'success', 'message' => __('Lock removed.')]
            : ['type' => 'error', 'message' => $reason]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function storeLock(StoreElockLockRequest $request, Property $property, AddElockLock $addElockLock): RedirectResponse
    {
        $room = $request->filled('room_id') ? Room::query()->where('property_id', $property->id)->find($request->integer('room_id')) : null;
        $added = ($request->filled('room_id') && $room === null)
            ? false
            : $addElockLock($request->user(), $property, (string) $request->input('name'), (string) $request->input('lock_no'), $room);

        Inertia::flash('toast', $added !== false
            ? ['type' => 'success', 'message' => __('Lock added.')]
            : ['type' => 'error', 'message' => __('That lock number is already used, or the room is not available or already has a lock.')]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function cancelJob(Request $request, Property $property, KeyportJob $keyportJob, CancelKeyportJob $cancelKeyportJob): RedirectResponse
    {
        Inertia::flash('toast', $cancelKeyportJob($request->user(), $keyportJob)
            ? ['type' => 'success', 'message' => __('Card job cancelled.')]
            : ['type' => 'error', 'message' => __('The desk PC already picked this job up, so it cannot be cancelled.')]);

        return back();
    }
}
