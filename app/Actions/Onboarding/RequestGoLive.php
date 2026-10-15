<?php

namespace App\Actions\Onboarding;

use App\Enums\PropertyStatus;
use App\Models\GoLiveReview;
use App\Models\Property;
use App\Models\User;
use App\Notifications\GoLiveRequested;
use App\Services\Onboarding\GoLiveReadiness;
use App\Services\Platform\GoLiveRecipients;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RequestGoLive
{
    public function __construct(
        private GoLiveReadiness $readiness,
        private PlatformSettings $platformSettings,
        private GoLiveRecipients $recipients,
    ) {}

    /**
     * Send the setup for review. Sets the property to PendingApproval and records the request. Nothing operational
     * opens: the business date opens when an admin approves. Returns false when the platform does not ask for
     * approval, the property is not Onboarding, a required step is not done, or a request is already pending.
     */
    public function __invoke(User $actor, Property $property): bool
    {
        return DB::transaction(function () use ($actor, $property): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if (
                ! $this->platformSettings->current()->require_go_live_approval
                || $property->status !== PropertyStatus::Onboarding
                || ! $this->readiness->isReady($property)
                || GoLiveReview::query()->where('property_id', $property->id)->pending()->exists()
            ) {
                return false;
            }

            $review = GoLiveReview::query()->create([
                'property_id' => $property->id,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]);

            $property->update(['status' => PropertyStatus::PendingApproval, 'went_live_at' => null]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.go-live-requested')
                ->withProperties(['from' => PropertyStatus::Onboarding->value, 'to' => PropertyStatus::PendingApproval->value, 'go_live_review_id' => $review->id])
                ->log('Property submitted for go-live approval');

            Notification::send($this->recipients->forRequest($property), new GoLiveRequested($property->name, $property->code, $actor->name));

            return true;
        });
    }
}
