<?php

namespace App\Actions\DoorLocks;

use App\Enums\KeyportJobStatus;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use Illuminate\Support\Facades\DB;

/**
 * Hand the oldest pending job of the device's property to the device and start its lease. Returns null when there is
 * no work. Two devices never get the same job.
 */
class ClaimKeyportJob
{
    public function __invoke(KeyportDevice $device): ?KeyportJob
    {
        return DB::transaction(function () use ($device): ?KeyportJob {
            $job = KeyportJob::query()
                ->where('property_id', $device->property_id)
                ->where('status', KeyportJobStatus::Pending->value)
                ->where('expires_at', '>', now())
                ->where(fn ($query) => $query->whereNull('device_id')->orWhere('device_id', $device->id))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            $job?->forceFill([
                'status' => KeyportJobStatus::Claimed,
                'device_id' => $device->id,
                'claimed_at' => now(),
                'lease_until' => now()->addSeconds((int) config('keyport.lease_seconds')),
            ])->save();

            return $job;
        });
    }
}
