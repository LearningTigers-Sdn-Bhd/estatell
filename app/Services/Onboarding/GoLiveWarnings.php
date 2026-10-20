<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\GoLiveWarningData;
use App\Enums\OnboardingStep;
use App\Enums\SystemTransactionCode;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\RatePlan;

/**
 * Problems that never block go-live: each one names the section that fixes it. Reads only.
 * See docs/17-onboarding/02-wizard-steps.md (Review page).
 */
class GoLiveWarnings
{
    /**
     * @return list<GoLiveWarningData>
     */
    public function forProperty(Property $property): array
    {
        return [
            ...$this->taxesNotOnChargeItems($property),
            ...$this->noTaxOnRooms($property),
            ...$this->followersMissingRoomTypes($property),
        ];
    }

    /**
     * An enabled tax that applies to neither rooms nor any active charge item, so it is never charged.
     *
     * @return list<GoLiveWarningData>
     */
    private function taxesNotOnChargeItems(Property $property): array
    {
        $chargeCodeIds = $property->chargeItems()->active()->pluck('transaction_code_id')->all();

        return array_values($property->taxes()
            ->where('enabled', true)
            ->get()
            ->reject(fn ($tax): bool => $tax->applicableTransactionCodes()
                ->where(fn ($query) => $query
                    ->whereIn('transaction_codes.id', $chargeCodeIds)
                    ->orWhere('system_key', SystemTransactionCode::RoomRevenue->value))
                ->exists())
            ->map(fn ($tax): GoLiveWarningData => $this->warning(
                $property,
                OnboardingStep::ChargeItems,
                "{$tax->name} is not applied to any charge item.",
            ))
            ->values()
            ->all());
    }

    /**
     * No enabled tax applies to room revenue. Skipped when the owner answered "We don't charge tax".
     *
     * @return list<GoLiveWarningData>
     */
    private function noTaxOnRooms(Property $property): array
    {
        if ($this->isSkipped($property, OnboardingStep::Taxes)) {
            return [];
        }

        $appliesToRooms = $property->taxes()
            ->where('enabled', true)
            ->whereHas('applicableTransactionCodes', fn ($query) => $query->where('system_key', SystemTransactionCode::RoomRevenue->value))
            ->exists();

        return $appliesToRooms
            ? []
            : [$this->warning($property, OnboardingStep::Taxes, 'No tax is applied to rooms.')];
    }

    /**
     * A follower plan (derived from a basic rate plan) that does not include a room type its parent has.
     *
     * @return list<GoLiveWarningData>
     */
    private function followersMissingRoomTypes(Property $property): array
    {
        $followers = RatePlan::query()
            ->where('property_id', $property->id)
            ->active()
            ->whereNotNull('parent_rate_plan_id')
            ->with(['roomTypes', 'parent.roomTypes'])
            ->get();

        $warnings = [];

        foreach ($followers as $follower) {
            $missing = ($follower->parent->roomTypes ?? collect())
                ->reject(fn ($roomType): bool => $follower->roomTypes->contains('id', $roomType->id));

            if ($missing->isNotEmpty()) {
                $warnings[] = $this->warning(
                    $property,
                    OnboardingStep::BasicRate,
                    "{$follower->name} does not include {$missing->pluck('name')->implode(', ')}.",
                );
            }
        }

        return $warnings;
    }

    private function isSkipped(Property $property, OnboardingStep $step): bool
    {
        return OnboardingSkip::query()
            ->where('property_id', $property->id)
            ->where('step', $step->value)
            ->exists();
    }

    private function warning(Property $property, OnboardingStep $step, string $message): GoLiveWarningData
    {
        return new GoLiveWarningData(
            step: $step->value,
            stepLabel: $step->label(),
            message: $message,
            url: route($step->routeName(), ['property' => $property->code], absolute: false),
        );
    }
}
