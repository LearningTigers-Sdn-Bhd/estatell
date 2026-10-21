<?php

namespace App\Actions\Notifications;

use App\Models\MailEvent;

class PruneMailEvents
{
    /**
     * Delete mail log rows older than the retention period. Returns how many rows were deleted. Each row is deleted
     * as a model, so its blind index rows go with it. Running it again finds nothing more to delete.
     */
    public function __invoke(): int
    {
        $deleted = 0;

        MailEvent::query()->where('sent_at', '<', now()->subDays(MailEvent::RETENTION_DAYS))->each(function (MailEvent $event) use (&$deleted): void {
            $event->delete();
            $deleted++;
        });

        return $deleted;
    }
}
