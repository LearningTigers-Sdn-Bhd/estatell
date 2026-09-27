<?php

namespace App\Actions\Onboarding;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterPropertyOwner
{
    /**
     * Create a property in onboarding and its owner in one transaction, so every staff user has a property.
     *
     * Type and country stay empty until the Basics wizard step, which also seeds the property defaults.
     *
     * @param  array{name: string, email: string, password: string, property_name: string}  $input
     */
    public function __invoke(array $input): User
    {
        return DB::transaction(function () use ($input): User {
            $property = Property::query()->create([
                'name' => $input['property_name'],
                'status' => PropertyStatus::Onboarding,
                'onboarding_started_at' => now(),
            ]);

            return $property->users()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
        });
    }
}
