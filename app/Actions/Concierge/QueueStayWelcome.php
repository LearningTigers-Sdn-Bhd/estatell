<?php

namespace App\Actions\Concierge;

use App\Events\StayCheckedIn;
use App\Jobs\SendStayWelcomeJob;
use App\Models\ReservationUnit;

/**
 * On a check-in, asks for the welcome now (a stay with no PIN to wait for is welcomed at once) and schedules the deadline of this check-in,
 * which sends it without the door PIN if the PIN has not come in time. The deadline carries the check-in time, so it belongs to this
 * check-in alone. See `WelcomeStay`.
 */
class QueueStayWelcome
{
    public function __invoke(StayCheckedIn $event): void
    {
        $checkedInAt = ReservationUnit::query()->where('property_id', $event->propertyId)->whereKey($event->reservationUnitId)->value('checked_in_at');

        SendStayWelcomeJob::dispatch($event->propertyId, $event->reservationUnitId);

        if ($checkedInAt !== null) {
            SendStayWelcomeJob::dispatch($event->propertyId, $event->reservationUnitId, $checkedInAt->getTimestamp())->delay(now()->addSeconds(SendStayWelcomeJob::DEADLINE_SECONDS));
        }
    }
}
