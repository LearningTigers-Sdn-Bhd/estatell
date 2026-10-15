<?php

namespace App\Actions\Onboarding;

use App\Enums\NotificationType;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use App\Notifications\OnboardingReminder;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Onboarding\DueOnboardingReminders;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Staff\PropertyOwners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Sends the due onboarding reminder to the property's owner and moves the stage up. The property row is locked and the
 * stage is checked again, so a second run sends nothing twice. A property that is skipped keeps its stage, and is
 * checked again on the next run.
 */
class SendOnboardingReminder
{
    public function __construct(
        private DueOnboardingReminders $dueReminders,
        private GoLiveRequests $goLiveRequests,
        private NotificationPreferences $preferences,
        private OnboardingProgress $progress,
        private PropertyOwners $propertyOwners,
    ) {}

    public function __invoke(Property $property, ?CarbonImmutable $now = null): bool
    {
        $now ??= now();

        return DB::transaction(function () use ($property, $now): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $stage = $this->dueReminders->dueStage($property, $now);

            if ($stage === null || $property->status !== PropertyStatus::Onboarding || $this->goLiveRequests->pending($property) !== null) {
                return false;
            }

            $owner = $this->propertyOwners->firstFor($property);

            if ($owner === null || $owner->deactivated_at !== null) {
                return false;
            }

            if ($this->preferences->channelsFor($owner, NotificationType::OnboardingReminder) === []) {
                return false;
            }

            $property->forceFill(['onboarding_reminder_stage' => $stage])->save();

            $owner->notify($this->notification($property, $owner));

            return true;
        });
    }

    /**
     * An owner who has not verified the email gets the setup link, because the plain link needs a verified email.
     */
    private function notification(Property $property, User $owner): OnboardingReminder
    {
        $nextStep = $this->progress->currentStep($property);
        $usesSetupLink = ! $owner->hasVerifiedEmail();

        return new OnboardingReminder(
            propertyName: $property->name,
            propertyCode: $property->code,
            stepLabel: $nextStep->label(),
            actionUrl: $usesSetupLink
                ? URL::temporarySignedRoute('guest.owner-setup.show', now()->addDays(7), ['user' => $owner])
                : route((string) $nextStep->routeName(), ['property' => $property->code]),
            usesSetupLink: $usesSetupLink,
        );
    }
}
