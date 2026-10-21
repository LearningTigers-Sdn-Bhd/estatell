<?php

namespace App\Actions\Channels;

use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ChannelBookingCancelled;
use App\Notifications\ChannelBookingReceived;
use App\Notifications\ChannelReservationNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class NotifyChannelReservationStaff
{
    /**
     * Tell the property's reservation staff about a channel booking. The recipients are the active staff of the
     * property who hold the permission of the notification type, and the notification applies the property and user
     * preferences. The caller runs this after the reservation is saved. See docs/04-notifications/01-notification-types.md.
     *
     * @param  class-string<ChannelBookingReceived|ChannelBookingCancelled>  $notification
     */
    public function __invoke(Reservation $reservation, string $notification, string $channelName): void
    {
        $reservation->loadMissing('property');

        $message = new $notification(
            $reservation->id,
            $reservation->reference,
            $reservation->property->code,
            $reservation->property->name,
            $channelName,
            (string) $reservation->units()->min('arrival_date'),
        );

        Notification::send($this->recipients($reservation, $message), $message);
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Reservation $reservation, ChannelReservationNotification $message): Collection
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
