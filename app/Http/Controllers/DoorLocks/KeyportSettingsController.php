<?php

namespace App\Http\Controllers\DoorLocks;

use App\Actions\DoorLocks\AddElockLock;
use App\Actions\DoorLocks\CancelKeyportJob;
use App\Actions\DoorLocks\CreateKeyportPairingCode;
use App\Actions\DoorLocks\RevokeKeyportDevice;
use App\Data\DoorLocks\DoorLockData;
use App\Data\Keyport\KeyportDeviceData;
use App\Data\Keyport\KeyportJobRowData;
use App\Enums\LockProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Keyport\StoreElockLockRequest;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Door locks > Keyport: the front-desk PCs, the eLock locks and the latest key card jobs. See docs/27-door-locks/02-keyport.md.
 */
class KeyportSettingsController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/keyport/index', [
            'devices' => $property->keyportDevices()->latest('id')->get()->map(KeyportDeviceData::fromModel(...)),
            'locks' => $property->doorLocks()->ofProvider(LockProvider::ELock)->with('room')->orderBy('name')->get()->map(DoorLockData::fromModel(...)),
            'rooms' => Room::query()->where('property_id', $property->id)->whereNull('archived_at')->orderBy('position')->orderBy('number')->get(['id', 'number'])
                ->map(fn (Room $room): array => ['value' => (string) $room->id, 'label' => $room->number])->values(),
            'jobs' => $property->keyportJobs()->with('doorLock')->latest('id')->limit(30)->get()->map(KeyportJobRowData::fromModel(...)),
        ]);
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

        return to_route('settings.keyport.index', $property);
    }

    public function revokeDevice(Request $request, Property $property, KeyportDevice $keyportDevice, RevokeKeyportDevice $revokeKeyportDevice): RedirectResponse
    {
        $already = $keyportDevice->revoked_at !== null;
        $revokeKeyportDevice($request->user(), $keyportDevice);

        Inertia::flash('toast', $already
            ? ['type' => 'error', 'message' => __('That computer is already revoked.')]
            : ['type' => 'success', 'message' => __('Computer revoked.')]);

        return to_route('settings.keyport.index', $property);
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

        return to_route('settings.keyport.index', $property);
    }

    public function cancelJob(Request $request, Property $property, KeyportJob $keyportJob, CancelKeyportJob $cancelKeyportJob): RedirectResponse
    {
        Inertia::flash('toast', $cancelKeyportJob($request->user(), $keyportJob)
            ? ['type' => 'success', 'message' => __('Card job cancelled.')]
            : ['type' => 'error', 'message' => __('The desk PC already picked this job up, so it cannot be cancelled.')]);

        return back();
    }
}
