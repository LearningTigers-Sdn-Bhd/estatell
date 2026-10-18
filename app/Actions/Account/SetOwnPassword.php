<?php

namespace App\Actions\Account;

use App\Models\User;

class SetOwnPassword
{
    /**
     * Replace a temp password with the user's own. Clears the temp password, so it can no longer be exported.
     *
     * A company user made by a property has an unverified email: the verification email goes out now, at first
     * sign-in, because the property could have typed an address it does not own.
     */
    public function __invoke(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'temporary_password' => null,
            'temporary_password_expires_at' => null,
            'must_change_password' => false,
        ])->save();

        if ($user->is_company_user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }
}
