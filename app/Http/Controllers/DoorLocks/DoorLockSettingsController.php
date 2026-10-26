<?php

namespace App\Http\Controllers\DoorLocks;

use App\Actions\DoorLocks\AdjustLockClock;
use App\Actions\DoorLocks\DoorLockResult;
use App\Actions\DoorLocks\IssueDoorPasscode;
use App\Actions\DoorLocks\LinkDoorLock;
use App\Actions\DoorLocks\RevokeDoorPasscode;
use App\Actions\DoorLocks\SaveDoorLockConnection;
use App\Actions\DoorLocks\SyncDoorLocks;
use App\Actions\DoorLocks\TestDoorLockConnection;
use App\Actions\DoorLocks\UnlockDoor;
use App\Data\DoorLocks\DoorLockConnectionData;
use App\Data\DoorLocks\DoorLockData;
use App\Data\DoorLocks\DoorPasscodeData;
use App\Enums\PasscodeKind;
use App\Enums\PasscodeMode;
use App\Enums\PasscodeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DoorLocks\IssuePasscodeRequest;
use App\Http\Requests\DoorLocks\SaveDoorLockConnectionRequest;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\Property;
use App\Models\Room;
use App\Services\DoorLocks\TtlockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DoorLockSettingsController extends Controller
{
    /**
     * The one Door locks page: the connection, the locks with their rooms, and the latest PINs. Bounded lists load once.
     */
    public function edit(Property $property): Response
    {
        $connection = $property->doorLockConnection;

        return Inertia::render('settings/door-locks/index', [
            'connection' => $connection === null ? null : DoorLockConnectionData::fromModel($connection),
            'passcodeModes' => PasscodeMode::options(),
            'passcodeKinds' => array_map(fn (PasscodeKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], PasscodeKind::cases()),
            'locks' => $property->doorLocks()->with('room')->orderBy('name')->get()->map(DoorLockData::fromModel(...)),
            'rooms' => Room::query()->where('property_id', $property->id)->whereNull('archived_at')->orderBy('position')->orderBy('number')->get(['id', 'number'])
                ->map(fn (Room $room): array => ['value' => (string) $room->id, 'label' => $room->number])->values(),
            'passcodes' => DoorPasscode::query()->where('property_id', $property->id)->with('doorLock')->latest('id')->limit(50)->get()->map(DoorPasscodeData::fromModel(...)),
        ]);
    }

    public function update(SaveDoorLockConnectionRequest $request, Property $property, SaveDoorLockConnection $saveDoorLockConnection): RedirectResponse
    {
        try {
            $saveDoorLockConnection($property, $request->user(), $request->connection());
        } catch (TtlockException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return to_route('settings.door-locks.edit', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Door lock connection saved.')]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function test(Request $request, Property $property, TestDoorLockConnection $testDoorLockConnection): RedirectResponse
    {
        $connection = $property->doorLockConnection;

        if ($connection === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the connection first.')]);

            return to_route('settings.door-locks.edit', $property);
        }

        $error = $testDoorLockConnection($connection, $request->user());

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Connected to TTLock.')]
            : ['type' => 'error', 'message' => $error]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function sync(Request $request, Property $property, SyncDoorLocks $syncDoorLocks): RedirectResponse
    {
        $connection = $property->doorLockConnection;

        if ($connection === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the connection first.')]);

            return to_route('settings.door-locks.edit', $property);
        }

        $count = $syncDoorLocks($property, $connection, $request->user());

        Inertia::flash('toast', $count === null
            ? ['type' => 'error', 'message' => $connection->last_error]
            : ['type' => 'success', 'message' => trans_choice(':count lock synced.|:count locks synced.', $count)]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function link(Request $request, Property $property, DoorLock $doorLock, LinkDoorLock $linkDoorLock): RedirectResponse
    {
        $roomId = $request->integer('room_id');
        $room = $roomId > 0 ? Room::query()->where('property_id', $property->id)->find($roomId) : null;
        $linked = ($roomId > 0 && $room === null) ? false : $linkDoorLock($request->user(), $doorLock, $room);

        Inertia::flash('toast', $linked
            ? ['type' => 'success', 'message' => __('Lock linked.')]
            : ['type' => 'error', 'message' => __('That room is not available, or it already has a lock.')]);

        return to_route('settings.door-locks.edit', $property);
    }

    public function unlock(Request $request, Property $property, DoorLock $doorLock, UnlockDoor $unlockDoor): RedirectResponse
    {
        return $this->flash($property, $unlockDoor($doorLock, $request->user()), __('Door unlocked.'));
    }

    public function clock(Request $request, Property $property, DoorLock $doorLock, AdjustLockClock $adjustLockClock): RedirectResponse
    {
        return $this->flash($property, $adjustLockClock($doorLock, $request->user()), __('Lock clock set.'));
    }

    public function storePasscode(IssuePasscodeRequest $request, Property $property, IssueDoorPasscode $issueDoorPasscode): RedirectResponse
    {
        $lock = $property->doorLocks()->with('property.doorLockConnection')->findOrFail($request->integer('door_lock_id'));
        [$startsAt, $endsAt] = $request->window($property->settings->time_zone ?? 'Asia/Kuala_Lumpur');

        $result = $issueDoorPasscode(
            $lock,
            PasscodeKind::from((string) $request->input('kind')),
            (string) $request->input('label'),
            $startsAt,
            $endsAt,
            'manual:'.Str::uuid(),
            $request->filled('code') ? (string) $request->input('code') : null,
            actor: $request->user(),
        );

        return $this->flash($property, $result, __('PIN issued.'));
    }

    public function revoke(Request $request, Property $property, DoorPasscode $doorPasscode, RevokeDoorPasscode $revokeDoorPasscode): RedirectResponse
    {
        return $this->flash($property, $revokeDoorPasscode($doorPasscode->load('doorLock.property.doorLockConnection'), $request->user()), __('PIN revoked.'));
    }

    public function retry(Request $request, Property $property, DoorPasscode $doorPasscode, IssueDoorPasscode $issueDoorPasscode): RedirectResponse
    {
        if ($doorPasscode->status !== PasscodeStatus::Failed) {
            return $this->flash($property, DoorLockResult::refused(DoorLockResult::REFUSAL_UNCHANGEABLE), '');
        }

        $doorPasscode->load('doorLock.property.doorLockConnection');
        $unit = $doorPasscode->reservationUnit;

        $result = $issueDoorPasscode(
            $doorPasscode->doorLock,
            $doorPasscode->kind,
            $doorPasscode->label,
            $doorPasscode->starts_at,
            $doorPasscode->ends_at,
            $doorPasscode->idempotency_key,
            $doorPasscode->code,
            $unit,
            $request->user(),
        );

        return $this->flash($property, $result, __('PIN issued.'));
    }

    /**
     * A refusal is an error toast, a warning is shown with the success, and nothing else is an HTTP error.
     */
    private function flash(Property $property, DoorLockResult $result, string $success): RedirectResponse
    {
        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $result->message]
            : ['type' => $result->warning === null ? 'success' : 'warning', 'message' => trim($success.' '.$result->warning)]);

        return to_route('settings.door-locks.edit', $property);
    }
}
