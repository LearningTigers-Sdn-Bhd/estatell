<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Late check-out form shows when it opens: the late check-out policy fee before tax, the expected departure
 * time now and the property's standard check-out time.
 */
class UnitLateCheckOutPreviewData extends Data
{
    public function __construct(
        public ?string $fee,
        public string $departureTime,
        public string $standardTime,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, '', '', $error);
    }
}
