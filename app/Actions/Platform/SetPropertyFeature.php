<?php

namespace App\Actions\Platform;

use App\Enums\LockProvider;
use App\Enums\PlatformFeature;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\OpenPosShifts;
use Laravel\Pennant\Feature;

class SetPropertyFeature
{
    public function __construct(private OpenPosShifts $openPosShifts) {}

    /**
     * Turn a feature on or off for one property and log the change. Does nothing when the value is unchanged. Door locks also
     * takes the providers the property uses (`ttlock`, `elock`); none given means both. Other features ignore `$providers`.
     * Native POS cannot be turned off while the property has an unclosed register shift (`open_pos_shifts_exist`): the
     * call returns that refusal code and changes nothing. Otherwise it returns null.
     *
     * @param  list<string>|null  $providers
     */
    public function __invoke(User $admin, Property $property, PlatformFeature $feature, bool $active, ?array $providers = null): ?string
    {
        if (! $active && $feature === PlatformFeature::Pos && $this->openPosShifts->exist($property)) {
            return 'open_pos_shifts_exist';
        }

        $features = Feature::for($property);
        $current = $features->value($feature->featureClass());
        $wasActive = $current !== false;

        $value = $feature === PlatformFeature::DoorLocks ? $this->providerValue($providers) : true;

        if ($active) {
            if ($current === $value) {
                return null;
            }

            $features->activate($feature->featureClass(), $value);
        } else {
            if (! $wasActive) {
                return null;
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

        return null;
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
