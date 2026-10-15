<?php

namespace App\Services\Onboarding;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finds the onboarding properties that may get a reminder. Reads only. The reference time is the last onboarding
 * activity, or the start of onboarding when there is none. Stage 1 is due after 24 hours and stage 2 after 72 hours.
 * The highest due stage is sent, so a long-idle property gets one email, not two.
 */
class DueOnboardingReminders
{
    /**
     * @return Collection<int, Property>
     */
    public function get(?CarbonImmutable $now = null): Collection
    {
        $cutoff = ($now ?? now())->subHours(24);

        return Property::query()
            ->where('status', PropertyStatus::Onboarding->value)
            ->where('onboarding_reminder_stage', '<', 2)
            ->where(fn ($query) => $query
                ->where('onboarding_last_activity_at', '<=', $cutoff)
                ->orWhere(fn ($query) => $query
                    ->whereNull('onboarding_last_activity_at')
                    ->where('onboarding_started_at', '<=', $cutoff)))
            ->get();
    }

    /**
     * The stage that is due for the property now, or null when none is due.
     */
    public function dueStage(Property $property, ?CarbonImmutable $now = null): ?int
    {
        $now ??= now();
        $referenceAt = $property->onboarding_last_activity_at ?? $property->onboarding_started_at;

        if ($referenceAt === null) {
            return null;
        }

        $isIdleFor72Hours = $referenceAt->lessThanOrEqualTo($now->subHours(72));

        return match ($property->onboarding_reminder_stage) {
            0 => $isIdleFor72Hours ? 2 : ($referenceAt->lessThanOrEqualTo($now->subHours(24)) ? 1 : null),
            1 => $isIdleFor72Hours ? 2 : null,
            default => null,
        };
    }
}
