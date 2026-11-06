<?php

namespace App\Services\Reservations;

use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Services\Rates\Pricing\NightPrice;

/**
 * What changing a unit's guests does, worked out before any write by `UnitPartyChangePreview`. `nights` lists every
 * night with its price now and after; `charged` nights change only when `repriceCharged` is set, and an overridden
 * night keeps its price. `postedAddOns` are the charged per-guest add-ons to correct (`PostedAddOnAdjustments`). A plan with a `refusal` writes nothing.
 */
final readonly class UnitPartyPlan
{
    /**
     * @param  list<array{night: UnitNight, price: ?NightPrice, charged: bool, overridden: bool, changes: bool}>  $nights
     * @param  list<int>  $childAges
     * @param  list<PostedAddOnAdjustment>  $postedAddOns
     */
    private function __construct(
        public ?string $refusal,
        public ?ReservationUnit $unit = null,
        public int $adults = 0,
        public array $childAges = [],
        public array $nights = [],
        public bool $fromChannel = false,
        public bool $repriceCharged = false,
        public string $before = '0.00',
        public string $after = '0.00',
        public array $postedAddOns = [],
    ) {}

    /**
     * @param  list<array{night: UnitNight, price: ?NightPrice, charged: bool, overridden: bool, changes: bool}>  $nights
     * @param  list<int>  $childAges
     * @param  list<PostedAddOnAdjustment>  $postedAddOns
     */
    public static function planned(ReservationUnit $unit, int $adults, array $childAges, array $nights, bool $fromChannel, bool $repriceCharged, string $before, string $after, array $postedAddOns = []): self
    {
        return new self(null, $unit, $adults, $childAges, $nights, $fromChannel, $repriceCharged, $before, $after, $postedAddOns);
    }

    public static function refused(string $refusal): self
    {
        return new self($refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
