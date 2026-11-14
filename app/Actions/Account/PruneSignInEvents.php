<?php

namespace App\Actions\Account;

use App\Models\SignInEvent;

class PruneSignInEvents
{
    /**
     * Delete sign-in log rows older than the retention period. Returns how many rows were deleted. Each row is deleted
     * as a model, so its blind index rows go with it. Running it again finds nothing more to delete.
     */
    public function __invoke(): int
    {
        $deleted = 0;

        SignInEvent::query()->where('occurred_at', '<', now()->subDays(SignInEvent::RETENTION_DAYS))->each(function (SignInEvent $event) use (&$deleted): void {
            $event->delete();
            $deleted++;
        });

        return $deleted;
    }
}
