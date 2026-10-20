<?php

namespace App\Services\Onboarding;

use App\Data\Platform\OnboardingBoardRowData;
use App\Models\Property;

/**
 * Reads the onboarding board rows for the admin list and the partner's Properties page. Reads only.
 */
class OnboardingBoard
{
    public function __construct(private OnboardingProgress $progress) {}

    /**
     * The current step, completion percentage and last activity of each property, keyed by property code. Last
     * activity falls back to the day onboarding started when the owner has not done anything yet.
     *
     * @param  iterable<Property>  $properties
     * @return array<string, OnboardingBoardRowData>
     */
    public function rows(iterable $properties): array
    {
        $rows = [];

        foreach ($properties as $property) {
            $summary = $this->progress->summary($property);
            $lastActivity = $property->onboarding_last_activity_at ?? $property->onboarding_started_at ?? $property->created_at;

            $rows[$property->code] = new OnboardingBoardRowData(
                currentStep: $summary['current']->label(),
                percent: $summary['total'] === 0 ? 0 : intdiv($summary['done'] * 100, $summary['total']),
                lastActivity: $lastActivity->translatedFormat('j M Y, H:i'),
            );
        }

        return $rows;
    }
}
