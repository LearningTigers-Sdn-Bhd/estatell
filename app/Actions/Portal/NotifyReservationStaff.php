<?php

namespace App\Actions\Portal;

use App\Models\Reservation;
use App\Models\User;
use App\Notifications\PortalBookingCancelled;
use App\Notifications\PortalBookingCreated;
use App\Notifications\PortalCancellationRequested;
use App\Notifications\PortalReservationNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class NotifyReservationStaff
{
    /**
     * Tell the property's reservation staff about a company portal event. The recipients are the active staff of the
     * property who hold the permission of the notification type, and the notification applies the property and user
     * preferences. The caller runs this after its transaction has committed. See docs/04-notifications/01-notification-types.md.
     *
     * @param  class-string<PortalBookingCreated|PortalBookingCancelled|PortalCancellationRequested>  $notification
     */
    public function __invoke(Reservation $reservation, string $notification): void
    {
        $reservation->loadMissing('property', 'company');

        $message = new $notification(
            $reservation->id,
            $reservation->reference,
            $reservation->property->code,
            $reservation->property->name,
            $reservation->company->name ?? '',
            (string) $reservation->units()->min('arrival_date'),
        );

        Notification::send($this->recipients($reservation, $message), $message);
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Reservation $reservation, PortalReservationNotification $message): Collection
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($reservation->property_id);

        try {
            return User::query()
                ->where('property_id', $reservation->property_id)
                ->where('is_admin', false)
                ->whereNull('deactivated_at')
                ->permission((string) $message->type->recipientPermission())
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
