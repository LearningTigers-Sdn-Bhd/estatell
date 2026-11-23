<?php

namespace App\Jobs;

use App\Actions\Pos\SyncLoyverseGuest;
use App\Enums\PosConnectionStatus;
use App\Models\PosConnection;
use App\Services\Pos\Loyverse\LoyverseException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Create or rename the Loyverse customer of a checked-in unit. It runs on the `loyverse` queue behind the `loyverse` rate
 * limiter (the account allows 300 requests per 300 seconds), releases itself when Loyverse answers 429, and gives up quietly
 * when the merchant has revoked access.
 */
class SyncLoyverseGuestJob implements ShouldQueue
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

    public function handle(SyncLoyverseGuest $sync): void
    {
        $connection = PosConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->status !== PosConnectionStatus::Connected) {
            return;
        }

        try {
            $sync($connection, $this->reservationUnitId);
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
