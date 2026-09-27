<?php

namespace App\Actions\Platform;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SuspendProperty
{
    /**
     * Suspend the property and remember its status. Returns false when it cannot be suspended.
     */
    public function __invoke(User $admin, Property $property): bool
    {
        return DB::transaction(function () use ($admin, $property): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $previousStatus = $property->status;

            if (! $previousStatus->canTransitionTo(PropertyStatus::Suspended)) {
                return false;
            }

            $property->update([
                'status' => PropertyStatus::Suspended,
                'suspended_from_status' => $previousStatus,
                'suspended_at' => now(),
            ]);

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('property.suspended')
                ->withProperties(['from' => $previousStatus->value, 'to' => PropertyStatus::Suspended->value])
                ->log('Property suspended');

            return true;
        });
    }
}
