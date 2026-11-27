<?php

namespace App\Data\Platform;

use App\Models\Property;
use App\Models\PropertyGroup;
use Spatie\LaravelData\Data;

/**
 * One row of Admin › Property groups: the group and its properties by name. See docs/03-staff/04-property-groups.md.
 */
class PropertyGroupData extends Data
{
    /**
     * @param  list<array{code: string, name: string}>  $properties
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $properties,
    ) {}

    /**
     * Expects `properties` loaded.
     */
    public static function fromModel(PropertyGroup $group): self
    {
        return new self(
            id: $group->id,
            name: $group->name,
            properties: array_values($group->properties
                ->sortBy(fn (Property $property): string => mb_strtolower($property->name))
                ->map(fn (Property $property): array => ['code' => $property->code, 'name' => $property->name])
                ->all()),
        );
    }
}
