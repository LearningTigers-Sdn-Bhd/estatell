<?php

namespace App\Actions\DoorLocks;

use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use App\Enums\LockProvider;
use App\Enums\UnitStatus;
use App\Models\DoorLock;
use App\Models\KeyportJob;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\DoorLocks\StayLocks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Ask a front-desk PC to make, rewrite, erase or read the key card of a stay at an eLock lock. Estatell decides the lock number
 * and the dates; Keyport only writes them. Nothing is written until a person puts a card on the reader. Returns the job (the
 * existing one when `$key` was used before), or a message saying why it was refused, writing nothing. `$actor` is null for the
 * check-in listener.
 */
class CreateKeyportJob
{
    public function __construct(private StayLocks $stayLocks) {}

    public function __invoke(?User $actor, ReservationUnit $unit, DoorLock $lock, KeyportJobType $type, ?string $key = null): KeyportJob|string
    {
        if ($lock->provider !== LockProvider::ELock || $lock->property_id !== $unit->property_id || $lock->lock_no === null) {
            return __('That lock does not use key cards.');
        }

        // Only the lock of the room the stay sleeps in. A lock id from the request must never reach another room's door. The one
        // exception is erasing a card this stay itself made at that lock, so a guest who moved room can have the old card wiped.
        if (! $this->stayLocks->currentElock($unit)->contains('id', $lock->id) && ($type !== KeyportJobType::Erase || $this->lastSerial($unit, $lock) === null)) {
            return __('That lock is not in this stay\'s room.');
        }

        if (($type === KeyportJobType::Issue || $type === KeyportJobType::Rewrite) && $unit->status !== UnitStatus::CheckedIn) {
            return __('The guest must be checked in before a card can be made.');
        }

        // A repeated key answers with the first job, but only when it is the same request. Another stay's key is a refusal.
        if ($key !== null && ($existing = KeyportJob::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing->property_id === $unit->property_id && $existing->reservation_unit_id === $unit->id && $existing->door_lock_id === $lock->id && $existing->type === $type
                ? $existing
                : __('That request was already used. Reload the page and try again.');
        }

        if (KeyportJob::query()->where('reservation_unit_id', $unit->id)->where('door_lock_id', $lock->id)->whereIn('status', [KeyportJobStatus::Pending->value, KeyportJobStatus::Claimed->value])->exists()) {
            return __('A key card for this stay is already waiting at the desk PC.');
        }

        $unit->loadMissing('reservation.booker', 'property.settings');
        $timeZone = $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur';
        $checkOut = CarbonImmutable::parse($unit->departure_date->toDateString().' '.$unit->departure_time, $timeZone);

        if ($type !== KeyportJobType::Erase && $type !== KeyportJobType::Read && ! $checkOut->isFuture()) {
            return __('The departure time has passed, so a card cannot be made.');
        }

        $serial = null;

        if ($type === KeyportJobType::Rewrite || $type === KeyportJobType::Erase) {
            $serial = $this->lastSerial($unit, $lock);

            if ($serial === null) {
                return __('No card was made for this stay yet, so there is nothing to change.');
            }
        }

        $job = (new KeyportJob)->forceFill([
            'public_id' => 'job_'.Str::ulid(),
            'property_id' => $unit->property_id,
            'door_lock_id' => $lock->id,
            'reservation_unit_id' => $unit->id,
            'issued_by' => $actor?->id,
            'type' => $type,
            'status' => KeyportJobStatus::Pending,
            'payload' => $this->payload($unit, $lock, $type, $timeZone, $checkOut, $serial),
            'idempotency_key' => $key ?? 'stay:'.$unit->id.':'.$lock->id.':'.$type->value.':'.Str::uuid(),
            'expires_at' => now()->addMinutes(config()->integer('keyport.job_minutes')),
        ]);
        $job->save();

        activity()->performedOn($job)->causedBy($actor)->event('door-locks.keyport-job-created')
            ->withProperties(['property_id' => $job->property_id, 'type' => $type->value, 'door_lock_id' => $lock->id])->log('Key card job created');

        return $job;
    }

    /**
     * What Keyport receives. `display` is for the on-screen prompt only. Times are property local time, as the contract says.
     *
     * @return array<string, mixed>
     */
    private function payload(ReservationUnit $unit, DoorLock $lock, KeyportJobType $type, string $timeZone, CarbonImmutable $checkOut, ?string $serial): array
    {
        $checkIn = ($unit->checked_in_at ?? now())->toImmutable()->setTimezone($timeZone);
        $lock->loadMissing('room');

        return [
            'display' => ['room' => $lock->room_id === null ? $lock->name : $lock->room->number, 'guest' => $unit->reservation->booker->full_name],
            'lock_no' => $lock->lock_no,
            'check_in' => $checkIn->format('Y-m-d H:i:s'),
            'check_out' => in_array($type, [KeyportJobType::Issue, KeyportJobType::Rewrite], true) ? $checkOut->format('Y-m-d H:i:s') : null,
            'flags' => 0,
            'expected_serial' => $serial,
        ];
    }

    /**
     * The serial of the newest card this stay made at the lock, from a finished issue or rewrite.
     */
    private function lastSerial(ReservationUnit $unit, DoorLock $lock): ?string
    {
        return KeyportJob::query()
            ->where('reservation_unit_id', $unit->id)->where('door_lock_id', $lock->id)
            ->whereIn('type', [KeyportJobType::Issue->value, KeyportJobType::Rewrite->value])
            ->where('status', KeyportJobStatus::Succeeded->value)->whereNotNull('serial')
            ->latest('id')->value('serial');
    }
}
