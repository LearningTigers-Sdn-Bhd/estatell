<?php

namespace App\Actions\Onboarding;

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\ChangeRatePlanBasis;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rates\UpdateRatePlan;
use App\Enums\PricingBasis;
use App\Enums\WeekendNights;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Closure;
use RuntimeException;

/**
 * The plan work both Basic rate and an own-prices More rate share. Rows of room types, each priced per room or per guest,
 * become one plan per basis used. It runs inside the caller's transaction and throws when a step is refused, so the
 * caller rolls everything back.
 */
class SaveOnboardingPricedPlans
{
    /** Days of prices a save writes, counted from today: 365 days in all. */
    public const DAYS_AHEAD = 364;

    public function __construct(
        private ChangeRatePlanBasis $changeRatePlanBasis,
        private CreateRatePlan $createRatePlan,
        private UpdateRatePlan $updateRatePlan,
        private SyncRatePlanRoomTypes $syncRatePlanRoomTypes,
        private ArchiveRatePlan $archiveRatePlan,
        private SaveRates $saveRates,
    ) {}

    /**
     * Give each basis the rows use a plan, make each plan sell exactly its rows' room types, and save the prices from
     * today to today + 364 days in the property time zone. An existing candidate with that basis is kept. A basis with no
     * plan takes a candidate left over from the other basis and switches it, and only creates a plan when none is left.
     * A candidate with no row is emptied and archived. A weekday price goes to every night that is not a weekend night,
     * and `$sameEveryNight` sends it to all 7 nights.
     *
     * Returns the plans by basis value.
     *
     * @param  list<RatePlan>  $candidates  The plans the rows replace, in list order.
     * @param  Closure(PricingBasis, list<PricingBasis>): string  $nameFor  The plan name for a basis, given every basis used.
     * @param  list<array{room_type_id: int, price_by: PricingBasis, weekday: string, weekend: string|null}>  $rows
     * @return array<string, RatePlan>
     *
     * @throws RuntimeException when a step is refused.
     */
    public function __invoke(Property $property, User $actor, array $candidates, Closure $nameFor, WeekendNights $weekendNights, bool $sameEveryNight, array $rows): array
    {
        $bases = $this->basesOf($rows);
        $plans = $this->plansFor($property, $actor, $candidates, $bases, $nameFor);

        foreach ($plans['byBasis'] as $basis => $plan) {
            $name = $nameFor(PricingBasis::from($basis), $bases);

            if ($plan->name !== $name) {
                $this->must($this->updateRatePlan->__invoke($actor, $plan, ['name' => $name]));
            }
        }

        $this->linkRoomTypes($actor, $plans, $rows);
        $this->saveAmounts($property, $actor, $plans['byBasis'], $weekendNights, $sameEveryNight, $rows);

        foreach ($plans['unused'] as $plan) {
            $this->must($this->archiveRatePlan->__invoke($actor, $plan));
        }

        return $plans['byBasis'];
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
     * @param  list<RatePlan>  $candidates
     * @param  list<PricingBasis>  $bases
     * @return array{byBasis: array<string, RatePlan>, unused: list<RatePlan>}
     */
    private function plansFor(Property $property, User $actor, array $candidates, array $bases, Closure $nameFor): array
    {
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
                'name' => $nameFor($basis, $bases),
                'pricing_basis' => $basis->value,
            ]);

            $this->must($created !== null);
            $byBasis[$basis->value] = $created;
        }

        return ['byBasis' => $byBasis, 'unused' => array_values($candidates)];
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

    private function must(bool $succeeded): void
    {
        if (! $succeeded) {
            throw new RuntimeException('Refused.');
        }
    }
}
