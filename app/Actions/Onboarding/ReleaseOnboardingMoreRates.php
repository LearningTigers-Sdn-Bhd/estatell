<?php

namespace App\Actions\Onboarding;

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Onboarding\MoreRatePlans;
use RuntimeException;

class ReleaseOnboardingMoreRates
{
    public function __construct(
        private MoreRatePlans $moreRatePlans,
        private ArchiveRatePlan $archiveRatePlan,
        private SyncRatePlanRoomTypes $syncRatePlanRoomTypes,
    ) {}

    /**
     * Prepare the saved More rates for the Basic Rate save, inside its transaction. A saved More rate with no row is
     * archived. A row that changed kind (Follows or Own prices) archives its old plans and is saved as new. A follower
     * gives up the room types its row dropped, so the Basic Rate plan it follows can stop selling them.
     *
     * Returns the rows, with `plan_ids` emptied for the rows that start over.
     *
     * @param  list<array<string, mixed>>  $rows  See SaveOnboardingMoreRates.
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when a step is refused.
     */
    public function __invoke(Property $property, User $actor, array $rows): array
    {
        $saved = $this->moreRatePlans->forProperty($property)->keyBy('id');
        $kept = [];

        foreach ($rows as $index => $row) {
            $plans = $saved->only($row['plan_ids'])->values();

            if ($plans->isEmpty()) {
                $rows[$index]['plan_ids'] = [];

                continue;
            }

            $savedFollows = $plans->first()->parent_rate_plan_id !== null;

            if ($savedFollows !== ($row['mode'] === 'follows')) {
                foreach ($plans as $plan) {
                    $this->must($this->archiveRatePlan->__invoke($actor, $plan));
                    $kept[] = $plan->id;
                }

                $rows[$index]['plan_ids'] = [];

                continue;
            }

            $kept = [...$kept, ...$plans->pluck('id')->all()];

            if ($savedFollows) {
                $plan = $plans->first();
                $current = $plan->roomTypes()->active()->pluck('room_types.id')->all();

                $this->must($this->syncRatePlanRoomTypes->__invoke($actor, $plan, array_values(array_intersect($current, $row['room_type_ids']))));
            }
        }

        foreach ($saved->except($kept) as $plan) {
            /** @var RatePlan $plan */
            $this->must($this->archiveRatePlan->__invoke($actor, $plan));
        }

        return $rows;
    }

    private function must(bool $succeeded): void
    {
        if (! $succeeded) {
            throw new RuntimeException('Refused.');
        }
    }
}
