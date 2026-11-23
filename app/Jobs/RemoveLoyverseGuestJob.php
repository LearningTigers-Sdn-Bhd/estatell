<?php

namespace App\Jobs;

use App\Actions\Pos\RemoveLoyverseGuest;
use App\Enums\PosConnectionStatus;
use App\Models\PosConnection;
use App\Services\Pos\Loyverse\LoyverseException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Delete the Loyverse customer of a unit that checked out, so cashiers never see departed guests. Same queue, rate
 * limiter and retry rules as `SyncLoyverseGuestJob`.
 */
class RemoveLoyverseGuestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $connectionId, public readonly int $reservationUnitId)
    {
        $this->onQueue('loyverse')->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('loyverse')];
    }

    public function handle(RemoveLoyverseGuest $remove): void
    {
        $connection = PosConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->status !== PosConnectionStatus::Connected) {
            return;
        }

        try {
            $remove($connection, $this->reservationUnitId);
        } catch (LoyverseException $exception) {
            if ($exception->retryAfter !== null) {
                $this->release($exception->retryAfter);

                return;
            }

            if (! $exception->reauthRequired) {
                throw $exception;
            }
        }
    }
}
