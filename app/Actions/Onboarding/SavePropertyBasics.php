<?php

namespace App\Actions\Onboarding;

use App\Actions\Property\SeedPropertyDefaults;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SavePropertyBasics
{
    public function __construct(private SeedPropertyDefaults $seedPropertyDefaults) {}

    /**
     * Save the property identity and seed its defaults once its type and country are known.
     *
     * @param  array<string, mixed>  $attributes  Validated Basics fields.
     */
    public function __invoke(Property $property, array $attributes): void
    {
        DB::transaction(function () use ($property, $attributes): void {
            $property->fill([...$attributes, 'country_code' => 'MY']);
            $property->save();

            ($this->seedPropertyDefaults)($property, $property->type, $property->country_code);
        });
    }
}
