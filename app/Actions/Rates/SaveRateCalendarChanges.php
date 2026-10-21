<?php

namespace App\Actions\Rates;

use App\Data\Rates\RateRestrictionsData;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

class SaveRateCalendarChanges
{
    public function __construct(private SaveRates $saveRates) {}

    /**
     * Save the staged edits of the rate calendar together: one date of one plan and room type each, through `SaveRates`.
     *
     * A change with no price leaves the saved price alone. A change with a price replaces that date's prices by adults.
     * Either every change is saved or none is. Returns the number of changes saved, or false, without writing, when a
     * plan or room type no longer fits (archived, unlinked, a price on a package or derived plan).
     *
     * @param  list<array{rate_plan_id: int, room_type_id: int, date: string, amount: string|null, occupancy: list<array{adults: int, amount: string}>, restrictions: RateRestrictionsData}>  $changes
     */
    public function __invoke(User $actor, Property $property, array $changes): int|false
    {
        $plans = RatePlan::query()
            ->where('property_id', $property->id)
            ->whereIn('id', array_unique(array_column($changes, 'rate_plan_id')))
            ->get()
            ->keyBy('id');

        DB::beginTransaction();

        try {
            foreach ($changes as $change) {
                $plan = $plans->get($change['rate_plan_id']);

                $days = $change['amount'] === null ? [] : [
                    Date::parse($change['date'])->dayOfWeekIso => ['amount' => $change['amount'], 'hours' => [], 'occupancy' => $change['occupancy']],
                ];

                $written = $plan === null ? false : ($this->saveRates)($actor, $plan, $change['date'], $change['date'], [['room_type_id' => $change['room_type_id'], 'days' => $days]], $change['restrictions']);

                if ($written === false) {
                    DB::rollBack();

                    return false;
                }
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return count($changes);
    }
}
