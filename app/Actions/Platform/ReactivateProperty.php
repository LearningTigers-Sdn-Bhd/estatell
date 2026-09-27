<?php

namespace App\Actions\Platform;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReactivateProperty
{
    /**
     * Restore the status from before suspension. Returns false when the property is not suspended.
     */
    public function __invoke(User $admin, Property $property): bool
    {
        return DB::transaction(function () use ($admin, $property): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $previousStatus = $property->suspended_from_status;

            if (
                $property->status !== PropertyStatus::Suspended
                || ! $previousStatus instanceof PropertyStatus
                || ! $property->status->canTransitionTo($previousStatus)
            ) {
                return false;
            }

            $property->update([
                'status' => $previousStatus,
                'suspended_from_status' => null,
                'suspended_at' => null,
            ]);

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('property.reactivated')
                ->withProperties(['from' => PropertyStatus::Suspended->value, 'to' => $previousStatus->value])
                ->log('Property reactivated');

            return true;
        });
    }
}
