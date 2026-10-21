<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * A booking from a channel was saved as a reservation.
 */
class ChannelBookingReceived extends ChannelReservationNotification
{
    public function __construct(int $reservationId, string $reference, string $propertyCode, string $propertyName, string $channelName, string $arrivalDate)
    {
        parent::__construct(NotificationType::ChannelBookingReceived, $reservationId, $reference, $propertyCode, $propertyName, $channelName, $arrivalDate);
    }

    protected function title(): string
    {
        return __(':channel booking :reference', ['channel' => $this->channelName, 'reference' => $this->reference]);
    }

    protected function body(): string
    {
        return __('A booking from :channel was saved as :reference. Arrival: :date.', ['channel' => $this->channelName, 'reference' => $this->reference, 'date' => $this->arrivalDate]);
    }
}
