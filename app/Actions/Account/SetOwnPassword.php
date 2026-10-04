<?php

namespace App\Actions\Account;

use App\Models\User;

class SetOwnPassword
{
    /**
     * Replace a temp password with the user's own. Clears the temp password, so it can no longer be exported.
     */
    public function __invoke(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'temporary_password' => null,
            'temporary_password_expires_at' => null,
            'must_change_password' => false,
        ])->save();
    }
}
