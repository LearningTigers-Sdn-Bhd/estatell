<?php

namespace App\Actions\Platform;

use App\Enums\PlatformFeature;
use App\Models\Property;
use App\Models\User;
use Laravel\Pennant\Feature;

class SetPropertyFeature
{
    /**
     * Turn a feature on or off for one property and log the change. Does nothing when the value is unchanged.
     */
    public function __invoke(User $admin, Property $property, PlatformFeature $feature, bool $active): void
    {
        $features = Feature::for($property);

        if ($features->active($feature->featureClass()) === $active) {
            return;
        }

        if ($active) {
            $features->activate($feature->featureClass());
        } else {
            $features->deactivate($feature->featureClass());
        }

        activity()
            ->performedOn($property)
            ->causedBy($admin)
            ->event($active ? 'feature.activated' : 'feature.deactivated')
            ->withProperties(['feature' => $feature->value])
            ->log($active ? "{$feature->label()} turned on" : "{$feature->label()} turned off");
    }
}
