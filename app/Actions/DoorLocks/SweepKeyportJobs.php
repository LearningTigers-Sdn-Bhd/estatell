<?php

namespace App\Actions\DoorLocks;

use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use App\Models\KeyportJob;

/**
 * Close the jobs that time ran out on. A pending job past `expires_at` is expired and never offered. A claimed job
 * whose lease ran out with no result is `uncertain` when it was a card write (staff must check the card) and
 * `failed` when it was a read. Neither is ever offered again.
 */
class SweepKeyportJobs
{
    public function __invoke(int $propertyId): void
    {
        $now = now();

        KeyportJob::query()
            ->where('property_id', $propertyId)
            ->where('status', KeyportJobStatus::Pending->value)
            ->where('expires_at', '<=', $now)
            ->update(['status' => KeyportJobStatus::Expired->value, 'error_code' => 'job_expired', 'finished_at' => $now]);

        $lapsed = fn () => KeyportJob::query()
            ->where('property_id', $propertyId)
            ->where('status', KeyportJobStatus::Claimed->value)
            ->where('lease_until', '<=', $now);

        $lapsed()
            ->where('type', '!=', KeyportJobType::Read->value)
            ->update(['status' => KeyportJobStatus::Uncertain->value, 'error_code' => 'lease_expired', 'finished_at' => $now]);

        $lapsed()
            ->where('type', KeyportJobType::Read->value)
            ->update(['status' => KeyportJobStatus::Failed->value, 'error_code' => 'lease_expired', 'finished_at' => $now]);
    }
}
