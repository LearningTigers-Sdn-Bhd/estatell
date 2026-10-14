<?php

namespace App\Services\Rates;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Who can follow whom: which plans are eligible parents, why a plan cannot follow another, and which active plans
 * follow a parent. It reads only.
 */
class DerivedRatePlans
{
    /**
     * The plans this plan could follow, in display order. A plan that already follows one is not excluded,
     * so its parent can be changed.
     *
     * @return Collection<int, RatePlan>
     */
    public function eligibleParents(RatePlan $plan): Collection
    {
        if ($plan->pricing_basis === PricingBasis::DayUse || $this->followers($plan)->isNotEmpty()) {
            return new Collection;
        }

        $sold = $plan->roomTypes()->active()->pluck('room_types.id')->all();

        return RatePlan::query()
            ->with('roomTypes')
            ->where('property_id', $plan->property_id)
            ->active()
            ->whereKeyNot($plan->id)
            ->whereNull('parent_rate_plan_id')
            ->where('pricing_basis', $plan->pricing_basis)
            ->when($plan->pricing_basis === PricingBasis::Package, fn ($query) => $query
                ->where('package_unit', $plan->package_unit)
                ->where('fixed_nights', $plan->fixed_nights))
            ->ordered()
            ->get()
            ->filter(fn (RatePlan $parent): bool => array_diff($sold, $parent->roomTypes->pluck('id')->all()) === [])
            ->values();
    }

    /**
     * Why the plan cannot follow another plan, or null when it can.
     */
    public function cannotFollowReason(RatePlan $plan): ?string
    {
        if ($plan->pricing_basis === PricingBasis::DayUse) {
            return __('Day use plans set their own prices.');
        }

        $followers = $this->followers($plan)->pluck('name')->all();

        if ($followers !== []) {
            return trans_choice('{1} :names follows this plan, so it needs its own prices.|[2,*] :names follow this plan, so it needs its own prices.', count($followers), [
                'names' => Arr::join($followers, ', ', __(' and ')),
            ]);
        }

        $others = RatePlan::query()->where('property_id', $plan->property_id)->active()->whereKeyNot($plan->id)->exists();

        if (! $others) {
            return __('Add another plan first. This is your only rate plan.');
        }

        if ($this->eligibleParents($plan)->isEmpty()) {
            $names = RoomType::query()->whereIn('id', $plan->roomTypes()->pluck('room_types.id'))->active()->ordered()->pluck('name')->all();

            if ($plan->pricing_basis === PricingBasis::Package) {
                return $names === []
                    ? __('No other package has the same nights and charged unit.')
                    : __('No other package with the same nights and charged unit sells :names.', ['names' => Arr::join($names, ', ', __(' and '))]);
            }

            return $names === []
                ? __('No other plan sells at a nightly price like this one.')
                : __('No other plan sells :names at a nightly price.', ['names' => Arr::join($names, ', ', __(' and '))]);
        }

        return null;
    }

    /**
     * The active plans that follow this one.
     *
     * @return EloquentCollection<int, RatePlan>
     */
    public function followers(RatePlan $parent): Collection
    {
        return $parent->derivedPlans()->active()->ordered()->get();
    }

    /**
     * The staff message when active plans follow this plan, or null when none do.
     */
    public function followersMessage(RatePlan $parent): ?string
    {
        $names = $this->followers($parent)->pluck('name')->all();

        if ($names === []) {
            return null;
        }

        return trans_choice('{1} :names follows this plan.|[2,*] :names follow this plan.', count($names), [
            'names' => Arr::join($names, ', ', __(' and ')),
        ]);
    }

    /**
     * Why the nights of a package cannot change, or null when they can. A package that follows another takes its
     * nights from it, and a package that other plans follow must keep them.
     */
    public function nightsLockMessage(RatePlan $plan): ?string
    {
        if ($plan->parent_rate_plan_id !== null) {
            return __('The nights follow :name.', ['name' => $plan->loadMissing('parent')->parent->name]);
        }

        $names = $this->followers($plan)->pluck('name')->all();

        if ($names === []) {
            return null;
        }

        return trans_choice('{1} :names follows this plan, so its nights cannot change.|[2,*] :names follow this plan, so its nights cannot change.', count($names), [
            'names' => Arr::join($names, ', ', __(' and ')),
        ]);
    }

    /**
     * The staff message when unlinking these room types from the parent would leave an active follower without a price.
     * Returns null when no follower sells them.
     *
     * @param  list<int>  $removedRoomTypeIds
     */
    public function unlinkMessage(RatePlan $parent, array $removedRoomTypeIds): ?string
    {
        if ($removedRoomTypeIds === []) {
            return null;
        }

        $parts = [];

        foreach ($this->followers($parent)->load('roomTypes') as $follower) {
            $names = $follower->roomTypes
                ->whereNull('archived_at')
                ->filter(fn (RoomType $roomType): bool => in_array($roomType->id, $removedRoomTypeIds, true))
                ->pluck('name')
                ->all();

            if ($names !== []) {
                $parts[] = __(':plan follows this plan for :names.', ['plan' => $follower->name, 'names' => Arr::join($names, ', ', __(' and '))]);
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
