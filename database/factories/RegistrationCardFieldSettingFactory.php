<?php

namespace Database\Factories;

use App\Enums\RegistrationCardField;
use App\Models\Property;
use App\Models\RegistrationCardFieldSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationCardFieldSetting>
 */
class RegistrationCardFieldSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $field = RegistrationCardField::Phone;

        return [
            'property_id' => Property::factory(),
            'field' => $field,
            'visibility' => $field->defaultVisibility(),
            'position' => array_search($field, RegistrationCardField::cases(), true),
        ];
    }
}
