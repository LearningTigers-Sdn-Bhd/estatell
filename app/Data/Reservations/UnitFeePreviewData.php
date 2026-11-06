<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Cancel unit and Mark no-show forms show when they open: the policy fee before tax (read only text, never
 * sent back as an amount), the free window of a cancellation and how many add-ons "Also remove add-ons" would delete.
 * `onlineRefund` is what a cancel gives back through Curlec, and `onlineRefundWaived` the same with Do not charge.
 */
class UnitFeePreviewData extends Data
{
    public function __construct(
        public ?string $fee,
        public bool $withinFreeWindow,
        public ?int $freeUntilDays,
        public ?int $daysBeforeArrival,
        public int $unpostedAddOns,
        public ?string $error,
        public string $onlineRefund = '0.00',
        public string $onlineRefundWaived = '0.00',
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, false, null, null, 0, $error);
    }
}
