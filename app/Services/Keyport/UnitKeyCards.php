<?php

namespace App\Services\Keyport;

use App\Data\Keyport\KeyportDeviceData;
use App\Data\Keyport\KeyportJobRowData;
use App\Data\Keyport\UnitKeyCardsData;
use App\Enums\KeyportDeviceStatus;
use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use App\Enums\UnitStatus;
use App\Models\DoorLock;
use App\Models\KeyportJob;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\StayLocks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the key cards of a stay unit for the reservation page. Returns null when there is nothing to show: no card job was ever
 * made and the unit has no eLock lock in its room.
 */
class UnitKeyCards
{
    public function __construct(private StayLocks $stayLocks) {}

    public function forUnit(ReservationUnit $unit): ?UnitKeyCardsData
    {
        $jobs = KeyportJob::query()->where('reservation_unit_id', $unit->id)->with('doorLock')->latest('id')->limit(10)->get();
        $locks = $this->stayLocks->currentElock($unit);
        $previous = $this->previousLocks($unit, $locks);

        if ($jobs->isEmpty() && $locks->isEmpty()) {
            return null;
        }

        $devices = $unit->property->keyportDevices()->whereNull('revoked_at')->get();
        $online = $devices->contains(fn ($device): bool => KeyportDeviceData::status($device) === KeyportDeviceStatus::Online);

        return new UnitKeyCardsData(
            locks: array_values($locks->map(fn (DoorLock $lock): array => [
                'id' => $lock->id, 'name' => $lock->name, 'hasCard' => $this->hasCard($jobs, $lock), 'stale' => $this->isStale($unit, $lock), 'previous' => false,
            ])->concat($previous->map(fn (DoorLock $lock): array => [
                'id' => $lock->id, 'name' => $lock->name, 'hasCard' => true, 'stale' => false, 'previous' => true,
            ]))->all()),
            deskOnline: $online,
            hasDesk: $devices->isNotEmpty(),
            canMake: $unit->status === UnitStatus::CheckedIn && $devices->isNotEmpty() && $locks->isNotEmpty(),
            jobs: array_values($jobs->map(KeyportJobRowData::fromModel(...))->all()),
            syncing: $jobs->contains(fn (KeyportJob $job): bool => ! $job->status->isFinished()),
        );
    }

    /**
     * Locks outside the current room where this stay still has a card on record, e.g. after a room move. Only erase is offered.
     *
     * @param  Collection<int, DoorLock>  $current
     * @return Collection<int, DoorLock>
     */
    private function previousLocks(ReservationUnit $unit, Collection $current): Collection
    {
        $ids = KeyportJob::query()->where('reservation_unit_id', $unit->id)
            ->whereIn('type', [KeyportJobType::Issue->value, KeyportJobType::Rewrite->value])
            ->where('status', KeyportJobStatus::Succeeded->value)->whereNotNull('serial')
            ->pluck('door_lock_id')->unique()->diff($current->pluck('id'));

        // A lock is still carded when its newest finished card job is an issue or rewrite, not an erase.
        return DoorLock::query()->whereIn('id', $ids)->get()->filter(function (DoorLock $lock) use ($unit): bool {
            $last = KeyportJob::query()->where('reservation_unit_id', $unit->id)->where('door_lock_id', $lock->id)
                ->where('status', KeyportJobStatus::Succeeded->value)->latest('id')->first();

            return $last !== null && $last->type !== KeyportJobType::Erase;
        })->values();
    }

    /**
     * The card's end date no longer matches the stay: the stay was extended or shortened after the card was written.
     */
    private function isStale(ReservationUnit $unit, DoorLock $lock): bool
    {
        $last = KeyportJob::query()->where('reservation_unit_id', $unit->id)->where('door_lock_id', $lock->id)
            ->whereIn('type', [KeyportJobType::Issue->value, KeyportJobType::Rewrite->value])
            ->where('status', KeyportJobStatus::Succeeded->value)->whereNotNull('serial')->latest('id')->first();

        if ($last === null) {
            return false;
        }

        $timeZone = $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur';
        $checkOut = CarbonImmutable::parse($unit->departure_date->toDateString().' '.$unit->departure_time, $timeZone)->format('Y-m-d H:i:s');

        return ($last->payload['check_out'] ?? null) !== $checkOut;
    }

    /**
     * Whether a card is on record for the lock, so rewrite and erase can offer themselves.
     *
     * @param  Collection<int, KeyportJob>  $jobs
     */
    private function hasCard(Collection $jobs, DoorLock $lock): bool
    {
        return $jobs->contains(fn (KeyportJob $job): bool => $job->door_lock_id === $lock->id
            && $job->status === KeyportJobStatus::Succeeded
            && in_array($job->type, [KeyportJobType::Issue, KeyportJobType::Rewrite], true)
            && $job->serial !== null);
    }
}
