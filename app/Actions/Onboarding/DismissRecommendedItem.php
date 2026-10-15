<?php

namespace App\Actions\Onboarding;

use App\Models\Property;
use App\Models\RecommendedItemDismissal;
use App\Models\User;
use App\Services\Onboarding\RecommendedChecklist;
use Illuminate\Support\Facades\DB;

class DismissRecommendedItem
{
    /**
     * Mark a Recommended next item as Not needed, so the checklist stops showing it. Returns false, and writes and logs
     * nothing, for a key that cannot be dismissed (the logo, or an unknown key) or an item already dismissed.
     */
    public function __invoke(Property $property, User $actor, string $item): bool
    {
        if (! RecommendedChecklist::isDismissibleKey($item)) {
            return false;
        }

        return DB::transaction(function () use ($property, $actor, $item): bool {
            $dismissal = RecommendedItemDismissal::query()->firstOrCreate(
                ['property_id' => $property->id, 'item' => $item],
                ['dismissed_by' => $actor->id, 'dismissed_at' => now()],
            );

            if (! $dismissal->wasRecentlyCreated) {
                return false;
            }

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('onboarding.recommended-item-dismissed')
                ->withProperties(['property_id' => $property->id, 'item' => $item])
                ->log('Recommended item marked as not needed');

            return true;
        });
    }
}
