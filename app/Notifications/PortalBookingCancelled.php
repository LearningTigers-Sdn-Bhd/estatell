<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * A company cancelled its whole booking in the company portal, before its cut-off and with no charge.
 */
class PortalBookingCancelled extends PortalReservationNotification
{
    public function __construct(int $reservationId, string $reference, string $propertyCode, string $propertyName, string $companyName, string $arrivalDate)
    {
        parent::__construct(NotificationType::PortalBookingCancelled, $reservationId, $reference, $propertyCode, $propertyName, $companyName, $arrivalDate);
    }

    protected function title(): string
    {
        return __(':company cancelled :reference', ['company' => $this->companyName, 'reference' => $this->reference]);
    }

    protected function body(): string
    {
        return __(':company cancelled :reference in the company portal, with no charge. Arrival was :date.', ['company' => $this->companyName, 'reference' => $this->reference, 'date' => $this->arrivalDate]);
    }
}
