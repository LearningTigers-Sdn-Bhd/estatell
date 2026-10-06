<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Reinstate form shows when it opens: the unit total as booked and as it will be priced again.
 */
class UnitReinstatePreviewData extends Data
{
    public function __construct(
        public ?string $previousTotal,
        public ?string $newTotal,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, null, $error);
    }
}
