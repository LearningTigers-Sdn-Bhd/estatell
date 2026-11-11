<?php

namespace App\Actions\DoorLocks;

use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use App\Enums\LockProvider;
use App\Enums\UnitStatus;
use App\Models\DoorLock;
use App\Models\KeyportJob;
use App\Models\User;

/**
 * Staff remove an eLock lock. Returns null when removed, or the reason it was refused, writing nothing: it is not an eLock lock,
 * a card job is waiting at it, or a guest who is still checked in holds a card written for it (the panel could no longer rewrite
 * or erase that card). Finished jobs keep their history, since the job's lock link is cleared.
 */
class RemoveElockLock
{
    public function __invoke(User $actor, DoorLock $lock): ?string
    {
        if ($lock->provider !== LockProvider::ELock) {
            return __('That lock does not use key cards.');
        }

        if (KeyportJob::query()->where('door_lock_id', $lock->id)->whereIn('status', [KeyportJobStatus::Pending->value, KeyportJobStatus::Claimed->value])->exists()) {
            return __('A key card job is waiting at this lock. Cancel it first.');
        }

        if ($this->heldByCheckedInGuest($lock)) {
            return __('A guest who is still checked in has a card for this lock. Erase it first, or wait for check-out.');
        }

        activity()->performedOn($lock)->causedBy($actor)->event('door-locks.elock-removed')
            ->withProperties(['property_id' => $lock->property_id, 'lock_no' => $lock->lock_no])->log('eLock lock removed');
        $lock->delete();

        return null;
    }

    private function heldByCheckedInGuest(DoorLock $lock): bool
    {
        $unitIds = KeyportJob::query()->where('door_lock_id', $lock->id)->whereNotNull('reservation_unit_id')
            ->whereHas('reservationUnit', fn ($unit) => $unit->where('status', UnitStatus::CheckedIn->value))
            ->distinct()->pluck('reservation_unit_id');

        foreach ($unitIds as $unitId) {
            $last = KeyportJob::query()->where('door_lock_id', $lock->id)->where('reservation_unit_id', $unitId)
                ->where('status', KeyportJobStatus::Succeeded->value)->whereIn('type', [
                    KeyportJobType::Issue->value, KeyportJobType::Rewrite->value, KeyportJobType::Erase->value,
                ])->latest('id')->first();

            if ($last !== null && $last->type !== KeyportJobType::Erase) {
                return true;
            }
        }

        return false;
    }
}
