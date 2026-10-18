<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * A company asked the property to cancel a booking after its free cancellation cut-off. Staff decide on any charge in
 * the Cancel reservation Dialog.
 */
class PortalCancellationRequested extends PortalReservationNotification
{
    public function __construct(int $reservationId, string $reference, string $propertyCode, string $propertyName, string $companyName, string $arrivalDate)
    {
        parent::__construct(NotificationType::PortalCancellationRequested, $reservationId, $reference, $propertyCode, $propertyName, $companyName, $arrivalDate);
    }

    protected function title(): string
    {
        return __(':company asks to cancel :reference', ['company' => $this->companyName, 'reference' => $this->reference]);
    }

    protected function body(): string
    {
        return __(':company asks to cancel :reference. Arrival: :date. Cancel the reservation to resolve the request and decide on any charge.', ['company' => $this->companyName, 'reference' => $this->reference, 'date' => $this->arrivalDate]);
    }
}
