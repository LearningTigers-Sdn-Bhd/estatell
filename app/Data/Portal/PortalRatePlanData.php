<?php

namespace App\Data\Portal;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use Spatie\LaravelData\Data;

/**
 * A rate plan the company can book, with the short line shown under its name and the room types it sells. `summary`
 * is "{n}-night package" for a package, the names of its inclusions, or "Room only".
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

        return new self($ratePlan->id, $ratePlan->name, $summary, array_values($ratePlan->roomTypes->pluck('id')->map(intval(...))->all()));
    }
}
