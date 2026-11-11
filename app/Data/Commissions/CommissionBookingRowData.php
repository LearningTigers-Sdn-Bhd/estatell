<?php

namespace App\Data\Commissions;

use App\Data\Property\CurrencyData;
use Spatie\LaravelData\Data;

/**
 * One reservation on the admin Commissions Bookings tab: its entries due in the period, summed. Commission per service is
 * shown as a positive number; `outstanding` is signed from the property's side.
 */
class CommissionBookingRowData extends Data
{
    /**
     * @param  array<string, string>  $commissions  `CommissionService` value to amount.
     */
    public function __construct(
        public int $reservationId,
        public string $propertyName,
        public string $bookingReference,
        public string $sourceName,
        public string $bookedAt,
        public ?string $checkIn,
        public ?string $checkOut,
        public string $guestName,
        public string $value,
        public array $commissions,
        public string $outstanding,
        public ?CurrencyData $currency,
    ) {}
}
