<?php

namespace App\Actions\Onboarding;

use App\Enums\GoLiveDecision;
use App\Enums\PropertyStatus;
use App\Models\GoLiveReview;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WithdrawGoLiveRequest
{
    /**
     * The owner takes a pending request back to keep editing. Returns false when nothing is pending.
     */
    public function __invoke(User $actor, Property $property): bool
    {
        return DB::transaction(function () use ($actor, $property): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $review = GoLiveReview::query()->where('property_id', $property->id)->pending()->first();

            if ($property->status !== PropertyStatus::PendingApproval || $review === null) {
                return false;
            }

            $review->update(['decision' => GoLiveDecision::Withdrawn, 'decided_by' => $actor->id, 'decided_at' => now()]);
            $property->update(['status' => PropertyStatus::Onboarding]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.go-live-withdrawn')
                ->withProperties(['from' => PropertyStatus::PendingApproval->value, 'to' => PropertyStatus::Onboarding->value, 'go_live_review_id' => $review->id])
                ->log('Go-live request withdrawn');

            return true;
        });
    }
}
