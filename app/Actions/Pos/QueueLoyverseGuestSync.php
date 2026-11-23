<?php

namespace App\Actions\Pos;

use App\Enums\PosConnectionStatus;
use App\Enums\PosProvider;
use App\Events\StayCheckedIn;
use App\Events\StayCheckedOut;
use App\Events\StayRoomChanged;
use App\Features\PosIntegration;
use App\Jobs\RemoveLoyverseGuestJob;
use App\Jobs\SyncLoyverseGuestJob;
use App\Models\PosConnection;
use App\Models\Property;
use Laravel\Pennant\Feature;

/**
 * Stay events queue the Loyverse customer sync: a check-in or a room change creates or renames the customer, a
 * check-out (or an undone check-in) removes it. It does nothing while POS integration is off or the property has no
 * Connected Loyverse connection. The jobs run on the `loyverse` queue. See docs/33-pos/04-loyverse-integration.md.
 */
class QueueLoyverseGuestSync
{
    public function __invoke(StayCheckedIn|StayRoomChanged|StayCheckedOut $event): void
    {
        $connection = PosConnection::query()
            ->where('property_id', $event->propertyId)
            ->where('provider', PosProvider::Loyverse)
            ->where('status', PosConnectionStatus::Connected)
            ->first();

        if ($connection === null || ! Feature::for(Property::query()->findOrFail($event->propertyId))->active(PosIntegration::class)) {
            return;
        }

        if ($event instanceof StayCheckedOut) {
            RemoveLoyverseGuestJob::dispatch($connection->id, $event->reservationUnitId);

            return;
        }

        SyncLoyverseGuestJob::dispatch($connection->id, $event->reservationUnitId);
    }
}
