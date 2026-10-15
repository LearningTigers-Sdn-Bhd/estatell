<?php

namespace App\Actions\Onboarding;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Models\Property;
use App\Models\User;
use App\Services\Staff\PendingSignInUsers;
use App\Services\Staff\TemporaryPasswordGenerator;

class RefreshPendingTemporaryPasswords
{
    public function __construct(
        private PendingSignInUsers $pendingSignInUsers,
        private TemporaryPasswordGenerator $generator,
    ) {}

    /**
     * Give every staff user who has not signed in yet a new temp password that lasts 14 days from go-live. Runs inside
     * GoLive's transaction. Passwords are never logged: the activity records only who was refreshed.
     *
     * @return int How many users got a new password.
     */
    public function __invoke(Property $property, User $actor): int
    {
        $users = $this->pendingSignInUsers->query($property)->lockForUpdate()->get();

        foreach ($users as $user) {
            $temporaryPassword = $this->generator->generate();

            $user->forceFill([
                'password' => $temporaryPassword,
                'temporary_password' => $temporaryPassword,
                'temporary_password_expires_at' => now()->addDays(CreateStaffWithTemporaryPassword::VALID_DAYS),
                'must_change_password' => true,
                'temporary_password_shared_at' => null,
            ])->save();

            activity()
                ->performedOn($user)
                ->causedBy($actor)
                ->event('staff.temp-password-refreshed')
                ->withProperties(['property_id' => $property->id, 'email' => $user->email])
                ->log('Staff temp password refreshed at go-live');
        }

        return $users->count();
    }
}
