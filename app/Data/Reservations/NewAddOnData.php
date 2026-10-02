<?php

namespace App\Data\Reservations;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * One add-on to put on a unit. `quantity` counts only for PerItem items (default 1). `unitAmount` is a decimal string;
 * null copies the charge item's default. Nightly items ignore `quantity` and `serviceDate`.
 * See docs/12-reservations/04-add-ons.md.
 */
class NewAddOnData extends Data
{
    public function __construct(
        public int $chargeItemId,
        public ?int $quantity = null,
        public ?string $unitAmount = null,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public ?CarbonImmutable $serviceDate = null,
    ) {}
}
