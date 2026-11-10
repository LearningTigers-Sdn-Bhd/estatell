<?php

namespace App\Actions\Platform;

use App\Enums\LockProvider;
use App\Enums\PlatformFeature;
use App\Models\Property;
use App\Models\User;
use Laravel\Pennant\Feature;

class SetPropertyFeature
{
    /**
     * Turn a feature on or off for one property and log the change. Does nothing when the value is unchanged. Door locks also
     * takes the providers the property uses (`ttlock`, `elock`); none given means both. Other features ignore `$providers`.
     *
     * @param  list<string>|null  $providers
     */
    public function __invoke(User $admin, Property $property, PlatformFeature $feature, bool $active, ?array $providers = null): void
    {
        $features = Feature::for($property);
        $current = $features->value($feature->featureClass());
        $wasActive = $current !== false;

        $value = $feature === PlatformFeature::DoorLocks ? $this->providerValue($providers) : true;

        if ($active) {
            if ($current === $value) {
                return;
            }

            $features->activate($feature->featureClass(), $value);
        } else {
            if (! $wasActive) {
                return;
            }

            $features->deactivate($feature->featureClass());
        }

        $event = ! $active ? 'feature.deactivated' : ($wasActive ? 'feature.updated' : 'feature.activated');
        $verb = ! $active ? 'turned off' : ($wasActive ? 'changed' : 'turned on');

        activity()
            ->performedOn($property)
            ->causedBy($admin)
            ->event($event)
            ->withProperties(['feature' => $feature->value] + ($active && $feature === PlatformFeature::DoorLocks ? ['providers' => $value] : []))
            ->log("{$feature->label()} {$verb}");
    }

    /**
     * The providers to store, in a fixed order, never empty: an unknown or empty choice means every provider.
     *
     * @param  list<string>|null  $providers
     * @return list<string>
     */
    private function providerValue(?array $providers): array
    {
        $chosen = array_values(array_filter(LockProvider::cases(), fn (LockProvider $provider): bool => in_array($provider->value, $providers ?? [], true)));

        return array_map(fn (LockProvider $provider): string => $provider->value, $chosen === [] ? LockProvider::cases() : $chosen);
    }
}
