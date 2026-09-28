<?php

namespace App\Actions\Onboarding;

use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SavePropertyBasics
{
    /**
     * Saves the property identity and creates its settings row once. Setting defaults live in the migration.
     *
     * @param  array<string, mixed>  $attributes  Validated Basics fields.
     */
    public function __invoke(Property $property, array $attributes): void
    {
        DB::transaction(function () use ($property, $attributes): void {
            $property->fill([...$attributes, 'country_code' => 'MY']);
            $property->save();
            $property->settings()->firstOrCreate();
        });
    }
}
