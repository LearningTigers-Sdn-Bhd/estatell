<?php

namespace App\Actions\Onboarding;

use App\Enums\GoLiveDecision;
use App\Enums\OnboardingStep;
use App\Enums\PropertyStatus;
use App\Models\GoLiveReview;
use App\Models\Property;
use App\Models\User;
use App\Notifications\GoLiveRejected;
use App\Services\Staff\PropertyOwners;
use Illuminate\Support\Facades\DB;

class RejectGoLive
{
    public function __construct(private PropertyOwners $propertyOwners) {}

    /**
     * An admin sends a pending request back with a note and the sections to fix. The property returns to Onboarding.
     * Returns false when nothing is pending.
     *
     * @param  list<OnboardingStep>  $flaggedSteps
     */
    public function __invoke(User $admin, Property $property, string $note, array $flaggedSteps = []): bool
    {
        return DB::transaction(function () use ($admin, $property, $note, $flaggedSteps): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $review = GoLiveReview::query()->where('property_id', $property->id)->pending()->first();

            if ($property->status !== PropertyStatus::PendingApproval || $review === null) {
                return false;
            }

            $review->update([
                'decision' => GoLiveDecision::Rejected,
                'note' => $note,
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ]);

            foreach (array_unique(array_map(fn (OnboardingStep $step): string => $step->value, $flaggedSteps)) as $step) {
                $review->flags()->create(['step' => $step]);
            }

            $property->update(['status' => PropertyStatus::Onboarding]);

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('property.go-live-rejected')
                ->withProperties(['from' => PropertyStatus::PendingApproval->value, 'to' => PropertyStatus::Onboarding->value, 'go_live_review_id' => $review->id])
                ->log('Go-live request sent back');

            $this->propertyOwners->firstFor($property)?->notify(new GoLiveRejected($note));

            return true;
        });
    }
}
