<?php

namespace App\Services\Onboarding;

use App\Data\Platform\AdminWaitingPropertyData;
use App\Enums\GoLiveDecision;
use App\Enums\PropertyStatus;
use App\Models\GoLiveReview;
use App\Models\Property;

/**
 * Reads a property's go-live requests for the owner: the pending one, and the one an admin sent back. Reads only.
 */
class GoLiveRequests
{
    public function __construct(private GoLiveWarnings $warnings) {}

    public function pending(Property $property): ?GoLiveReview
    {
        return GoLiveReview::query()
            ->where('property_id', $property->id)
            ->pending()
            ->with('submitter')
            ->first();
    }

    /**
     * The sent date and warning count of each property's pending request, keyed by property code. For the admin list.
     *
     * @param  iterable<Property>  $properties
     * @return array<string, AdminWaitingPropertyData>
     */
    public function waitingRows(iterable $properties): array
    {
        $rows = [];

        foreach ($properties as $property) {
            $pending = $this->pending($property);

            if ($pending !== null) {
                $rows[$property->code] = new AdminWaitingPropertyData(
                    sentOn: $pending->submitted_at->translatedFormat('j M Y'),
                    warningsCount: count($this->warnings->forProperty($property)),
                );
            }
        }

        return $rows;
    }

    /**
     * The request an admin sent back, while it is still the latest one and the property is back in setup. A new
     * submit makes a newer request, so the note and the flags go away.
     */
    public function sentBack(Property $property): ?GoLiveReview
    {
        if ($property->status !== PropertyStatus::Onboarding) {
            return null;
        }

        $latest = GoLiveReview::query()
            ->where('property_id', $property->id)
            ->latest('submitted_at')
            ->latest('id')
            ->with(['flags', 'decider'])
            ->first();

        return $latest?->decision === GoLiveDecision::Rejected ? $latest : null;
    }

    /**
     * The step values the admin flagged on the request that was sent back.
     *
     * @return list<string>
     */
    public function flaggedSteps(Property $property): array
    {
        return $this->sentBack($property)?->flags->map(fn ($flag): string => $flag->step->value)->values()->all() ?? [];
    }
}
