<?php

namespace App\Http\Controllers\DoorLocks;

use App\Actions\DoorLocks\ClaimKeyportJob;
use App\Actions\DoorLocks\RecordKeyportHeartbeat;
use App\Actions\DoorLocks\ReportKeyportJobResult;
use App\Actions\DoorLocks\SweepKeyportJobs;
use App\Data\DoorLocks\KeyportJobData;
use App\Enums\KeyportApiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\DoorLocks\KeyportClaimRequest;
use App\Http\Requests\DoorLocks\KeyportJobResultRequest;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Sleep;

/**
 * The Keyport PC's job calls. New agents wait locally between empty claims; older agents still long poll.
 */
class KeyportJobController extends Controller
{
    public function claim(KeyportClaimRequest $request, RecordKeyportHeartbeat $heartbeat, SweepKeyportJobs $sweep, ClaimKeyportJob $claim): JsonResponse|Response
    {
        $device = $this->device($request);

        $heartbeat($device, $request->validated());
        $sweep($device->property_id);

        if ($request->boolean('busy')) {
            return response()->noContent();
        }

        $waitSeconds = $request->boolean('long_poll', true) ? (int) config('keyport.poll_seconds') : 0;
        $deadline = hrtime(true) / 1e9 + $waitSeconds;

        do {
            $job = $claim($device);

            if ($job !== null) {
                return response()->json(KeyportJobData::fromModel($job, $device->property->settings->time_zone)->toArray());
            }

            if (hrtime(true) / 1e9 >= $deadline) {
                break;
            }

            Sleep::sleep(1);
        } while (true);

        return response()->noContent();
    }

    public function result(KeyportJobResultRequest $request, string $job, ReportKeyportJobResult $report): JsonResponse|Response
    {
        $found = KeyportJob::query()
            ->where('device_id', $this->device($request)->id)
            ->where('public_id', $job)
            ->first();

        if ($found === null) {
            return KeyportApiError::JobNotFound->response();
        }

        return $report($found, $request->validated()) ? response()->noContent() : KeyportApiError::JobAlreadyFinished->response();
    }

    private function device(KeyportClaimRequest|KeyportJobResultRequest $request): KeyportDevice
    {
        $device = $request->attributes->get('keyport_device');
        assert($device instanceof KeyportDevice);

        return $device;
    }
}
