<?php

namespace App\Actions\DoorLocks;

use App\Enums\KeyportJobStatus;
use App\Models\KeyportJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Save the result a Keyport PC reports for its job. Sending the same result again is accepted and changes nothing.
 * Returns false, writing nothing, when the job already finished with a different result.
 */
class ReportKeyportJobResult
{
    /**
     * @param  array<string, mixed>  $result  The validated body: `status`, and optionally `serial`, `error_code`, `error_message`, `card`.
     */
    public function __invoke(KeyportJob $job, array $result): bool
    {
        $status = KeyportJobStatus::from(Arr::string($result, 'status'));
        $serial = $result['serial'] ?? null;
        $errorCode = $result['error_code'] ?? null;

        return DB::transaction(function () use ($job, $status, $serial, $errorCode, $result): bool {
            $current = KeyportJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if ($current->status->isFinished()) {
                return $current->status === $status && $current->serial === $serial && $current->error_code === $errorCode;
            }

            if (! $current->status->canTransitionTo($status)) {
                return false;
            }

            $current->forceFill([
                'status' => $status,
                'serial' => $serial,
                'error_code' => $errorCode,
                'result' => $result,
                'finished_at' => now(),
            ])->save();

            return true;
        });
    }
}
