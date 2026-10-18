<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * A company booked rooms in the company portal.
 */
class PortalBookingCreated extends PortalReservationNotification
{
    public function __construct(int $reservationId, string $reference, string $propertyCode, string $propertyName, string $companyName, string $arrivalDate)
    {
        parent::__construct(NotificationType::PortalBookingCreated, $reservationId, $reference, $propertyCode, $propertyName, $companyName, $arrivalDate);
    }

    protected function title(): string
    {
        return __(':company booked :reference', ['company' => $this->companyName, 'reference' => $this->reference]);
    }

    protected function body(): string
    {
        return __(':company booked :reference in the company portal. Arrival: :date.', ['company' => $this->companyName, 'reference' => $this->reference, 'date' => $this->arrivalDate]);
    }
}
