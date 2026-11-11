<?php

namespace App\Actions\DoorLocks;

use App\Enums\KeyportJobStatus;
use App\Models\KeyportJob;
use App\Models\User;

/**
 * Staff cancel a job no PC has claimed yet. Once claimed there is no push cancel: the PC reports the result. Returns false,
 * writing nothing, when the job is no longer pending.
 */
class CancelKeyportJob
{
    public function __invoke(User $actor, KeyportJob $job): bool
    {
        $cancelled = KeyportJob::query()->whereKey($job->id)->where('status', KeyportJobStatus::Pending->value)
            ->update(['status' => KeyportJobStatus::Cancelled->value, 'finished_at' => now()]);

        if ($cancelled === 0) {
            return false;
        }

        activity()->performedOn($job)->causedBy($actor)->event('door-locks.keyport-job-cancelled')
            ->withProperties(['property_id' => $job->property_id])->log('Key card job cancelled');

        return true;
    }
}
