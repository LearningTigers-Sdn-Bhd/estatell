<?php

namespace App\Actions\Fortify;

use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Data\Onboarding\NewPropertyOwnerData;
use App\Enums\PartnerStatus;
use App\Http\Controllers\Partner\JoinController;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private RegisterPropertyOwner $registerPropertyOwner, private Request $request) {}

    /**
     * Validate and create a newly registered property owner with their property. A partner signup link kept in the
     * session links the property to that partner, but only while the partner is still Active.
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

        $partner = $this->joiningPartner();
        $user = ($this->registerPropertyOwner)(new NewPropertyOwnerData(
            name: (string) $validated['name'],
            email: (string) $validated['email'],
            password: (string) $validated['password'],
            propertyName: (string) $validated['property_name'],
        ), $partner);

        $this->request->session()->forget(JoinController::SESSION_KEY);

        return $user;
    }

    /**
     * The Active partner from the signup link in the session, or null for a direct sign-up.
     */
    public function joiningPartner(): ?Partner
    {
        $partnerId = $this->request->session()->get(JoinController::SESSION_KEY);

        if ($partnerId === null) {
            return null;
        }

        return Partner::query()->whereKey($partnerId)->where('status', PartnerStatus::Active)->first();
    }
}
