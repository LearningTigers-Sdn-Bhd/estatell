<?php

namespace App\Data\Platform;

use App\Enums\LockProvider;
use App\Enums\PlatformFeature;
use App\Features\DoorLocks;
use App\Models\Property;
use Laravel\Pennant\Feature;
use Spatie\LaravelData\Data;

class PropertyFeatureData extends Data
{
    /**
     * Door locks only: `$providers` are the providers in use and `$providerOptions` every provider that can be chosen. Both are
     * null for other features.
     *
     * @param  list<string>|null  $providers
     * @param  list<array{value: string, label: string}>|null  $providerOptions
     */
    public function __construct(
        public PlatformFeature $feature,
        public string $label,
        public string $description,
        public bool $active,
        public ?array $providers = null,
        public ?array $providerOptions = null,
    ) {}

    /**
     * Every platform feature with its state for the property.
     *
     * @return list<self>
     */
    public static function forProperty(Property $property): array
    {
        $features = PlatformFeature::cases();
        $values = array_values(Feature::for($property)->values(array_map(
            fn (PlatformFeature $feature): string => $feature->featureClass(),
            $features,
        )));

        return array_map(fn (PlatformFeature $feature, mixed $value): self => new self(
            feature: $feature,
            label: $feature->label(),
            description: $feature->description(),
            active: (bool) $value,
            providers: $feature === PlatformFeature::DoorLocks ? array_map(fn (LockProvider $provider): string => $provider->value, DoorLocks::fromValue($value)) : null,
            providerOptions: $feature === PlatformFeature::DoorLocks ? LockProvider::options() : null,
        ), $features, $values);
    }
}
