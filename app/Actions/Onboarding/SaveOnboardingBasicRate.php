<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\UpdatePropertySettings;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\SellUnit;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\BasicRatePlans;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaveOnboardingBasicRate
{
    public function __construct(
        private BasicRatePlans $basicRatePlans,
        private UpdatePropertySettings $updatePropertySettings,
        private SaveOnboardingPricedPlans $savePricedPlans,
        private ReleaseOnboardingMoreRates $releaseMoreRates,
        private SaveOnboardingMoreRates $saveMoreRates,
    ) {}

    /**
     * Save the Basic rate page in one transaction. The weekend nights go to the property settings. The rows decide the
     * Basic Rate plans: one "Basic Rate" plan when every row uses the same basis, and "Basic Rate / Room" plus
     * "Basic Rate / Guest" when both are used. The seeded plan is reused and switched to the other basis when needed, a
     * plan with no room type left is archived, and each plan sells exactly the room types of its rows. Prices are saved
     * from today to today + 364 days in the property time zone, replacing what those dates held. `$sameEveryNight`
     * sends the weekday price to all 7 nights and ignores the weekend price.
     *
     * The More rates come with it. Rates the table dropped or changed to another kind are released first, so a Basic
     * Rate plan with no followers left can switch basis, and the rest are saved after the Basic Rate plans.
     *
     * Returns false, with nothing written, when the property is not onboarding, the rows are not exactly the property's
     * active room types sold by room, or a plan or price change is refused.
     *
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>  $rows
     * @param  list<array<string, mixed>>  $moreRates  See SaveOnboardingMoreRates.
     */
    public function __invoke(Property $property, User $actor, WeekendNights $weekendNights, bool $sameEveryNight, array $rows, array $moreRates = []): bool
    {
        try {
            DB::transaction(function () use ($property, $actor, $weekendNights, $sameEveryNight, $rows, $moreRates): void {
                $property = Property::query()->lockForUpdate()->findOrFail($property->id);
                $roomTypeIds = $property->roomTypes()->active()->where('sell_unit', SellUnit::Room)->pluck('id')->all();
                $rowIds = array_column($rows, 'room_type_id');

                if (
                    $property->status !== PropertyStatus::Onboarding
                    || $rows === []
                    || array_diff($roomTypeIds, $rowIds) !== []
                    || array_diff($rowIds, $roomTypeIds) !== []
                    || count($rowIds) !== count(array_unique($rowIds))
                ) {
                    throw new RuntimeException('Refused.');
                }

                $this->updatePropertySettings->__invoke($property, ['weekend_nights' => $weekendNights]);

                $moreRates = $this->releaseMoreRates->__invoke($property, $actor, $moreRates);

                $plans = $this->savePricedPlans->__invoke(
                    $property,
                    $actor,
                    array_values($this->basicRatePlans->forProperty($property)->all()),
                    fn (PricingBasis $basis, array $bases): string => $this->basicRatePlans->nameFor($basis, $bases),
                    $weekendNights,
                    $sameEveryNight,
                    $rows,
                );

                $this->saveMoreRates->__invoke($property, $actor, $plans, $weekendNights, $sameEveryNight, $moreRates);
            });
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
