<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\UpdatePropertySettings;
use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\ChangeRatePlanBasis;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rates\UpdateRatePlan;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Enums\SellUnit;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Onboarding\BasicRatePlans;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaveOnboardingBasicRate
{
    /** Days of prices a save writes, counted from today: 365 days in all. */
    public const DAYS_AHEAD = 364;

    public function __construct(
        private BasicRatePlans $basicRatePlans,
        private UpdatePropertySettings $updatePropertySettings,
        private ChangeRatePlanBasis $changeRatePlanBasis,
        private CreateRatePlan $createRatePlan,
        private UpdateRatePlan $updateRatePlan,
        private SyncRatePlanRoomTypes $syncRatePlanRoomTypes,
        private ArchiveRatePlan $archiveRatePlan,
        private SaveRates $saveRates,
    ) {}

    /**
     * Save the Basic rate table in one transaction. The weekend nights go to the property settings. The rows decide the
     * plans: one "Basic Rate" plan when every row uses the same basis, and "Basic Rate / Room" plus "Basic Rate / Guest"
     * when both are used. The seeded plan is reused and switched to the other basis when needed, a plan with no room
     * type left is archived, and each plan sells exactly the room types of its rows. Prices are saved from today to
     * today + 364 days in the property time zone, replacing what those dates held. A weekday price goes to every night
     * that is not a weekend night. `$sameEveryNight` sends it to all 7 nights and ignores the weekend price.
     *
     * Returns false, with nothing written, when the property is not onboarding, the rows are not exactly the property's
     * active room types sold by room, or a plan or price change is refused.
     *
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>  $rows
     */
    public function __invoke(Property $property, User $actor, WeekendNights $weekendNights, bool $sameEveryNight, array $rows): bool
    {
        try {
            DB::transaction(function () use ($property, $actor, $weekendNights, $sameEveryNight, $rows): void {
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

                $bases = $this->basesOf($rows);
                $plans = $this->plansFor($property, $actor, $bases);

                foreach ($plans['byBasis'] as $basis => $plan) {
                    $this->renameIfNeeded($actor, $plan, $this->basicRatePlans->nameFor(PricingBasis::from($basis), $bases));
                }

                $this->linkRoomTypes($actor, $plans, $rows);
                $this->saveAmounts($property, $actor, $plans['byBasis'], $weekendNights, $sameEveryNight, $rows);

                foreach ($plans['unused'] as $plan) {
                    $this->must($this->archiveRatePlan->__invoke($actor, $plan));
                }
            });
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * The bases the rows use, Room first.
     *
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>  $rows
     * @return list<PricingBasis>
     */
    private function basesOf(array $rows): array
    {
        $used = array_map(fn (array $row): PricingBasis => $row['price_by'], $rows);

        return array_values(array_filter(
            [PricingBasis::PerRoom, PricingBasis::PerGuest],
            fn (PricingBasis $basis): bool => in_array($basis, $used, true),
        ));
    }

    /**
     * A plan for each basis the rows use. An existing Basic Rate plan with that basis is kept. A basis with no plan takes
     * a plan left over from the other basis and switches it, and only creates a new plan when none is left.
     *
     * @param  list<PricingBasis>  $bases
     * @return array{byBasis: array<string, RatePlan>, unused: list<RatePlan>}
     */
    private function plansFor(Property $property, User $actor, array $bases): array
    {
        $candidates = $this->basicRatePlans->forProperty($property)->all();
        $byBasis = [];

        foreach ($bases as $basis) {
            foreach ($candidates as $key => $candidate) {
                if ($candidate->pricing_basis === $basis) {
                    $byBasis[$basis->value] = $candidate;
                    unset($candidates[$key]);

                    break;
                }
            }
        }

        foreach ($bases as $basis) {
            if (isset($byBasis[$basis->value])) {
                continue;
            }

            $leftover = array_shift($candidates);

            if ($leftover !== null) {
                $this->must($this->changeRatePlanBasis->__invoke($actor, $leftover, $basis));
                $byBasis[$basis->value] = $leftover->refresh();

                continue;
            }

            $created = $this->createRatePlan->__invoke($property, $actor, [
                'name' => $this->basicRatePlans->nameFor($basis, $bases),
                'pricing_basis' => $basis->value,
            ]);

            $this->must($created !== null);
            $byBasis[$basis->value] = $created;
        }

        return ['byBasis' => $byBasis, 'unused' => array_values($candidates)];
    }

    private function renameIfNeeded(User $actor, RatePlan $plan, string $name): void
    {
        if ($plan->name !== $name) {
            $this->must($this->updateRatePlan->__invoke($actor, $plan, ['name' => $name]));
        }
    }

    /**
     * Make each plan sell exactly the room types of its rows. A first pass only adds links, so a room type that moves from
     * one plan to the other is never without a plan. The second pass removes the old links, and unused plans are emptied.
     *
     * @param  array{byBasis: array<string, RatePlan>, unused: list<RatePlan>}  $plans
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>  $rows
     */
    private function linkRoomTypes(User $actor, array $plans, array $rows): void
    {
        $wanted = [];

        foreach ($plans['byBasis'] as $basis => $plan) {
            $wanted[$plan->id] = array_values(array_map(
                fn (array $row): int => $row['room_type_id'],
                array_filter($rows, fn (array $row): bool => $row['price_by']->value === $basis),
            ));
        }

        foreach ($plans['byBasis'] as $plan) {
            $current = $plan->roomTypes()->active()->pluck('room_types.id')->all();

            $this->must($this->syncRatePlanRoomTypes->__invoke($actor, $plan, array_values(array_unique([...$current, ...$wanted[$plan->id]]))));
        }

        foreach ($plans['byBasis'] as $plan) {
            $this->must($this->syncRatePlanRoomTypes->__invoke($actor, $plan, $wanted[$plan->id]));
        }

        foreach ($plans['unused'] as $plan) {
            $this->must($this->syncRatePlanRoomTypes->__invoke($actor, $plan, []));
        }
    }

    /**
     * @param  array<string, RatePlan>  $byBasis
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>  $rows
     */
    private function saveAmounts(Property $property, User $actor, array $byBasis, WeekendNights $weekendNights, bool $sameEveryNight, array $rows): void
    {
        $from = now($property->settings->time_zone)->startOfDay();
        $weekendDays = $weekendNights->isoWeekdays();

        foreach ($byBasis as $basis => $plan) {
            $roomTypes = [];

            foreach (array_filter($rows, fn (array $row): bool => $row['price_by']->value === $basis) as $row) {
                $days = [];

                foreach (range(1, 7) as $weekday) {
                    $isWeekend = ! $sameEveryNight && in_array($weekday, $weekendDays, true);
                    $days[$weekday] = ['amount' => $isWeekend ? $row['weekend'] : $row['weekday'], 'occupancy' => []];
                }

                $roomTypes[] = ['room_type_id' => $row['room_type_id'], 'days' => $days];
            }

            $this->must($this->saveRates->__invoke($actor, $plan, $from->toDateString(), $from->addDays(self::DAYS_AHEAD)->toDateString(), $roomTypes) !== false);
        }
    }

    /**
     * Stop the save and roll everything back when a step was refused.
     */
    private function must(bool $succeeded): void
    {
        if (! $succeeded) {
            throw new RuntimeException('Refused.');
        }
    }
}
