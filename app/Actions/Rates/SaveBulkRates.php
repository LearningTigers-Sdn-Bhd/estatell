<?php

namespace App\Actions\Rates;

use App\Data\Rates\RateRestrictionsData;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class SaveBulkRates
{
    public function __construct(private SaveRates $saveRates) {}

    /**
     * Save prices and restrictions of several plans over one date range, through `SaveRates` once for each plan.
     *
     * Either every plan is saved or none is. Returns the number of dates written, or false, without writing, when a
     * plan or room type no longer fits (archived, unlinked, a price on a package or derived plan, a min stay above a
     * max stay).
     *
     * @param  list<array{rate_plan_id: int, room_types: list<array{room_type_id: int, days: array<int, array{amount: string|null, hours: list<array{hours: int, amount: string}>, occupancy: list<array{adults: int, amount: string}>}>}>}>  $plans
     */
    public function __invoke(User $actor, Property $property, string $from, string $to, array $plans, RateRestrictionsData $restrictions): int|false
    {
        $ratePlans = RatePlan::query()
            ->where('property_id', $property->id)
            ->whereIn('id', array_column($plans, 'rate_plan_id'))
            ->get()
            ->keyBy('id');
        $written = 0;

        DB::beginTransaction();

        try {
            foreach ($plans as $plan) {
                $ratePlan = $ratePlans->get($plan['rate_plan_id']);
                $saved = $ratePlan === null ? false : ($this->saveRates)($actor, $ratePlan, $from, $to, $plan['room_types'], $restrictions);

                if ($saved === false) {
                    DB::rollBack();

                    return false;
                }

                $written += $saved;
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $written;
    }
}
