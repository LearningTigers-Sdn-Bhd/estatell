<?php

namespace App\Actions\Onboarding;

use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\SaveRatePlanDerivation;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rates\UpdateRatePlan;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Onboarding\MoreRatePlans;
use RuntimeException;

class SaveOnboardingMoreRates
{
    public function __construct(
        private MoreRatePlans $moreRatePlans,
        private CreateDerivedRatePlan $createDerivedRatePlan,
        private SaveRatePlanDerivation $saveRatePlanDerivation,
        private UpdateRatePlan $updateRatePlan,
        private SyncRatePlanRoomTypes $syncRatePlanRoomTypes,
        private SaveOnboardingPricedPlans $savePricedPlans,
    ) {}

    /**
     * Save the More rates table, inside the Basic rate transaction and after the Basic Rate plans are saved.
     *
     * A Follows row creates a plan that follows the Basic Rate plan of its basis (`CreateDerivedRatePlan`), or updates its
     * name, change and room types. An Own prices row works like the Basic rate table for its room types: one plan per
     * basis, "{name} / Room" and "{name} / Guest" when both are used, with prices for 365 days.
     *
     * Each row holds `plan_ids` (its saved plans, empty for a new row), `name`, `mode` (`follows` or `own`), and for Follows
     * `parent_basis`, `derivation`, `signed_value` and `room_type_ids`, and for Own prices `prices` (room type rows like the Basic rate table).
     *
     * @param  array<string, RatePlan>  $basicPlans  The Basic Rate plans by basis value.
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws RuntimeException when a step is refused.
     */
    public function __invoke(Property $property, User $actor, array $basicPlans, WeekendNights $weekendNights, bool $sameEveryNight, array $rows): void
    {
        foreach ($rows as $row) {
            $row['mode'] === 'follows'
                ? $this->saveFollower($property, $actor, $basicPlans, $row)
                : $this->saveOwn($property, $actor, $weekendNights, $sameEveryNight, $row);
        }
    }

    /**
     * @param  array<string, RatePlan>  $basicPlans
     * @param  array<string, mixed>  $row
     */
    private function saveFollower(Property $property, User $actor, array $basicPlans, array $row): void
    {
        /** @var PricingBasis $basis */
        $basis = $row['parent_basis'];
        /** @var RateDerivation $derivation */
        $derivation = $row['derivation'];
        $parent = $basicPlans[$basis->value] ?? null;

        $this->must($parent !== null);

        if ($row['plan_ids'] === []) {
            $this->must($this->createDerivedRatePlan->__invoke($actor, $parent, $row['name'], $derivation, $row['signed_value'], $row['room_type_ids']) !== null);

            return;
        }

        $plan = RatePlan::query()->whereKey($row['plan_ids'][0])->firstOrFail();

        if ($plan->name !== $row['name']) {
            $this->must($this->updateRatePlan->__invoke($actor, $plan, ['name' => $row['name']]));
        }

        $this->must($this->saveRatePlanDerivation->__invoke($actor, $plan, $parent->id, $derivation, $row['signed_value']));
        $this->must($this->syncRatePlanRoomTypes->__invoke($actor, $plan, $row['room_type_ids']));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function saveOwn(Property $property, User $actor, WeekendNights $weekendNights, bool $sameEveryNight, array $row): void
    {
        $candidates = array_values(RatePlan::query()->whereKey($row['plan_ids'])->ordered()->get()->all());

        $this->savePricedPlans->__invoke(
            $property,
            $actor,
            $candidates,
            fn (PricingBasis $basis, array $bases): string => $this->moreRatePlans->nameFor($row['name'], $basis, $bases),
            $weekendNights,
            $sameEveryNight,
            $row['prices'],
        );
    }

    private function must(bool $succeeded): void
    {
        if (! $succeeded) {
            throw new RuntimeException('Refused.');
        }
    }
}
