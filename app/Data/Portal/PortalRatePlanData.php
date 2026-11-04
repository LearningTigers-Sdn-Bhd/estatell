<?php

namespace App\Data\Portal;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use Spatie\LaravelData\Data;

/**
 * A rate plan the company can book, with the short line shown under its name and the room types it sells. `summary`
 * is "{n}-night package" for a package, the names of its inclusions, or "Room only". `minRooms`, `maxRooms` and
 * `groupRatePlanId` let the page switch a booking to the group rate once it has more rooms than the plan allows.
 */
class PortalRatePlanData extends Data
{
    /**
     * @param  list<int>  $roomTypeIds
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $summary,
        public array $roomTypeIds,
        public ?int $minRooms = null,
        public ?int $maxRooms = null,
        public ?int $groupRatePlanId = null,
    ) {}

    /**
     * The plan needs `roomTypes` and `inclusions.chargeItem` loaded.
     */
    public static function fromModel(RatePlan $ratePlan): self
    {
        $inclusions = $ratePlan->inclusions
            ->map(fn (RatePlanInclusion $inclusion): ?string => $inclusion->chargeItem?->name)
            ->filter()->unique()->values()->all();

        $summary = match (true) {
            $ratePlan->pricing_basis === PricingBasis::Package && $ratePlan->fixed_nights !== null => __(':count-night package', ['count' => $ratePlan->fixed_nights]),
            $inclusions !== [] => implode(', ', $inclusions),
            default => __('Room only'),
        };

        return new self(
            $ratePlan->id,
            $ratePlan->name,
            $summary,
            array_values($ratePlan->roomTypes->pluck('id')->map(intval(...))->all()),
            $ratePlan->pricing_basis === PricingBasis::PerBed ? null : $ratePlan->min_rooms,
            $ratePlan->pricing_basis === PricingBasis::PerBed ? null : $ratePlan->max_rooms,
            $ratePlan->group_rate_plan_id,
        );
    }
}
