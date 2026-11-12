<?php

namespace App\Data\Commissions;

use Spatie\LaravelData\Data;

class PayoutVoucherLineData extends Data
{
    public function __construct(
        public int $id,
        public ?string $dueDate,
        public ?int $reservationId,
        public ?string $bookingReference,
        public string $label,
        public ?string $reason,
        public string $amount,
    ) {}
}
