<?php

namespace App\Actions\Registration;

use App\Enums\RegistrationCardField;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SeedRegistrationCardFields
{
    /**
     * Create the registration card field rows the property is missing.
     * An existing row keeps its visibility, so repeated seeding never undoes an edit.
     */
    public function __invoke(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $existing = $property->registrationCardFields()->pluck('field')->map->value->all();

            foreach (RegistrationCardField::cases() as $position => $field) {
                if (in_array($field->value, $existing, true)) {
                    continue;
                }

                $property->registrationCardFields()->create([
                    'field' => $field,
                    'visibility' => $field->defaultVisibility(),
                    'position' => $position,
                ]);
            }
        });
    }
}
