<?php

namespace App\Events;

use App\Models\RatePlan;
/**
 * Prices or selling restrictions of a rate plan changed for some dates. A null room type means every room type the plan sells; a null plan means every plan.
 * The dates are the nights that changed, `Y-m-d`, both included.
 */
use App\Services\Channels\ChannelAriWindow;
use Illuminate\Foundation\Events\Dispatchable;

class RatesChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $propertyId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly ?int $roomTypeId = null,
        public readonly ?int $ratePlanId = null,
    ) {}

    /**
     * A price rule of the plan changed, so every day of the next 500 and every room type it sells is affected.
     */
    public static function forPlan(RatePlan $plan): self
    {
        return new self($plan->property_id, now()->toDateString(), now()->addDays(ChannelAriWindow::DAYS)->toDateString(), null, $plan->id);
    }
}
