<?php

namespace App\Data\Platform;

use App\Enums\PlatformFeature;
use App\Models\Property;
use Laravel\Pennant\Feature;
use Spatie\LaravelData\Data;

class PropertyFeatureData extends Data
{
    public function __construct(
        public PlatformFeature $feature,
        public string $label,
        public string $description,
        public bool $active,
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
        ), $features, $values);
    }
}
