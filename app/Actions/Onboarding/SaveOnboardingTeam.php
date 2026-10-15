<?php

namespace App\Actions\Onboarding;

use App\Actions\Staff\BulkCreateStaff;
use App\Enums\OnboardingStep;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveOnboardingTeam
{
    public function __construct(
        private BulkCreateStaff $bulkCreateStaff,
        private UnskipOnboardingStep $unskipStep,
    ) {}

    /**
     * Create the new rows as staff with temp passwords, all or nothing. Saved staff are not touched here: they are
     * removed in Settings after go-live. Saving at least one row removes a Skip. Returns how many people were added.
     *
     * @param  list<array{name: string, email: string, role_id: int}>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($property, $actor, $rows): int {
            $created = ($this->bulkCreateStaff)($property, $actor, $rows);

            ($this->unskipStep)($property, OnboardingStep::Team);

            return $created->count();
        });
    }
}
