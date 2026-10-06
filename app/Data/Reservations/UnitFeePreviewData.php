<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Cancel unit and Mark no-show forms show when they open: the policy fee before tax (read only text, never
 * sent back as an amount), the free window of a cancellation and how many add-ons "Also remove add-ons" would delete.
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
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, false, null, null, 0, $error);
    }
}
