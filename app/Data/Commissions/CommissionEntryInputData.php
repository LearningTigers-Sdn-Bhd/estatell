<?php

namespace App\Data\Commissions;

use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One entry for the commission ledger. `amount` is signed from the property's side. See docs/30-commission-fees/03-ledger.md.
 */
class CommissionEntryInputData extends Data
{
    public function __construct(
        public CommissionEntryType $type,
        public string $amount,
        public ?CarbonImmutable $dueDate = null,
        public ?int $reservationId = null,
        public ?CommissionService $service = null,
        public ?string $baseAmount = null,
        public ?string $rate = null,
        public ?int $gatewayPaymentId = null,
        public ?int $gatewayRefundId = null,
        public ?int $reversesEntryId = null,
        public ?string $idempotencyKey = null,
        public ?string $reason = null,
    ) {}
}
