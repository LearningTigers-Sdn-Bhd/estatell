<?php

namespace App\Actions\Onboarding;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Models\Property;
use App\Models\User;
use App\Services\Staff\PendingCompanyUsers;
use App\Services\Staff\PendingSignInUsers;
use App\Services\Staff\TemporaryPasswordGenerator;

class RefreshPendingTemporaryPasswords
{
    public function __construct(
        private PendingSignInUsers $pendingSignInUsers,
        private PendingCompanyUsers $pendingCompanyUsers,
        private TemporaryPasswordGenerator $generator,
    ) {}

    /**
     * Give every staff user and company user who has not signed in yet a new temp password that lasts 14 days from
     * go-live. A company user also linked at another property keeps theirs. Runs inside GoLive's transaction.
     * Passwords are never logged: the activity records only who was refreshed.
     *
     * @return array{staff: int, companies: int} How many users of each kind got a new password.
     */
    public function __invoke(Property $property, User $actor): array
    {
        $staff = $this->pendingSignInUsers->query($property)->lockForUpdate()->get();
        $companyUsers = $this->pendingCompanyUsers->query($property)->lockForUpdate()->get();

        foreach ($staff->concat($companyUsers) as $user) {
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
                ->event($user->is_company_user ? 'company.temp-password-refreshed' : 'staff.temp-password-refreshed')
                ->withProperties(['property_id' => $property->id, 'email' => $user->email])
                ->log($user->is_company_user ? 'Company temp password refreshed at go-live' : 'Staff temp password refreshed at go-live');
        }

        return ['staff' => $staff->count(), 'companies' => $companyUsers->count()];
    }
}
