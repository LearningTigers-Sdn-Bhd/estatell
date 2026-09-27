<?php

namespace App\Actions\Fortify;

use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private RegisterPropertyOwner $registerPropertyOwner) {}

    /**
     * Validate and create a newly registered property owner with their property.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'property_name' => ['required', 'string', 'max:255'],
        ])->validate();

        return ($this->registerPropertyOwner)($validated);
    }
}
