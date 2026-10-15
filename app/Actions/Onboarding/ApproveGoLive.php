<?php

namespace App\Actions\Onboarding;

use App\Enums\GoLiveDecision;
use App\Enums\PropertyStatus;
use App\Models\GoLiveReview;
use App\Models\Property;
use App\Models\User;
use App\Notifications\GoLiveApproved;
use App\Services\Property\PropertyToday;
use App\Services\Staff\PropertyOwners;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ApproveGoLive
{
    public function __construct(
        private GoLive $goLive,
        private PropertyToday $propertyToday,
        private PropertyOwners $propertyOwners,
    ) {}

    /**
     * An admin approves a pending request. Runs GoLive with the opening date (today in the property time zone, or an
     * earlier date for a property that asked to add past stays), records the decision and tells the owner. Returns
     * false when nothing is pending or the property cannot go live.
     */
    public function __invoke(User $admin, Property $property, ?CarbonInterface $openingDate = null): bool
    {
        return DB::transaction(function () use ($admin, $property, $openingDate): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $review = GoLiveReview::query()->where('property_id', $property->id)->pending()->first();

            if ($property->status !== PropertyStatus::PendingApproval || $review === null) {
                return false;
            }

            $openingDate ??= $this->propertyToday->date($property);

            if (! ($this->goLive)($admin, $property, $openingDate)) {
                return false;
            }

            $review->update([
                'decision' => GoLiveDecision::Approved,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'opening_date' => $openingDate->toDateString(),
            ]);

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('property.go-live-approved')
                ->withProperties(['go_live_review_id' => $review->id, 'opening_date' => $openingDate->toDateString()])
                ->log('Go-live request approved');

            $this->propertyOwners->firstFor($property)?->notify(new GoLiveApproved($property->name, $property->code));

            return true;
        });
    }
}
