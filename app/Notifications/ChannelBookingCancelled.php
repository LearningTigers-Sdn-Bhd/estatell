<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * A channel cancelled a booking, and its reservation was cancelled.
 */
class ChannelBookingCancelled extends ChannelReservationNotification
{
    public function __construct(int $reservationId, string $reference, string $propertyCode, string $propertyName, string $channelName, string $arrivalDate)
    {
        parent::__construct(NotificationType::ChannelBookingCancelled, $reservationId, $reference, $propertyCode, $propertyName, $channelName, $arrivalDate);
    }

    protected function title(): string
    {
        return __(':channel cancelled :reference', ['channel' => $this->channelName, 'reference' => $this->reference]);
    }

    protected function body(): string
    {
        return __(':channel cancelled :reference. Arrival was :date.', ['channel' => $this->channelName, 'reference' => $this->reference, 'date' => $this->arrivalDate]);
    }
}
