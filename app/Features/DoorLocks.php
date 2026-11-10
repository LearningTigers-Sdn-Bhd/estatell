<?php

namespace App\Features;

use App\Enums\LockProvider;
use App\Models\Property;
use Laravel\Pennant\Attributes\Name;
use Laravel\Pennant\Feature;

/**
 * Unlocks the door lock integration. The stored value says which lock providers the property uses: a list such as
 * `["ttlock", "elock"]`. The old value `true` (from before the choice existed) means every provider.
 */
#[Name('door-locks')]
class DoorLocks
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }

    /**
     * The providers the property may use. Empty while the feature is off.
     *
     * @return list<LockProvider>
     */
    public static function providers(Property $property): array
    {
        return self::fromValue(Feature::for($property)->value(self::class));
    }

    /**
     * @return list<LockProvider>
     */
    public static function fromValue(mixed $value): array
    {
        if ($value === true) {
            return LockProvider::cases();
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(LockProvider::cases(), fn (LockProvider $provider): bool => in_array($provider->value, $value, true)));
    }

    /**
     * Whether the feature is on and includes the provider.
     */
    public static function allows(Property $property, LockProvider $provider): bool
    {
        return in_array($provider, self::providers($property), true);
    }
}
