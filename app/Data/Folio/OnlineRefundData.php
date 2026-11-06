<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * One online refund shown above the folio rows. `failureReason` says why Curlec has not done it yet.
 * See docs/29-booking-engine/05-cancel-and-refund.md#refund-status.
 */
class OnlineRefundData extends Data
{
    public function __construct(
        public int $id,
        public string $amount,
        public BadgeData $status,
        public ?string $failureReason,
        public bool $canRetry,
    ) {}
}
