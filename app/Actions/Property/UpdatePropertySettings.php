<?php

namespace App\Actions\Property;

use App\Models\Property;

class UpdatePropertySettings
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Property $property, array $attributes): void
    {
        $property->settings->update($attributes);
    }
}
