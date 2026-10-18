<?php

namespace App\Actions\CityLedger;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\DB;

class ResetCompanyUserTemporaryPassword
{
    public function __construct(private TemporaryPasswordGenerator $generator) {}

    /**
     * Replace the company user's password with a new temp password that they must change after signing in. The
     * password changes, so `auth.session` signs them out everywhere.
     *
     * Returns false unless the company has an Active link, the user still has `must_change_password` and is not
     * deactivated, and has no Active link at another property (that property would be locked out).
     */
    public function __invoke(User $actor, Company $company): bool
    {
        return DB::transaction(function () use ($actor, $company): bool {
            $link = CompanyUser::query()->active()->where('company_id', $company->id)->first();

            if (! $link instanceof CompanyUser) {
                return false;
            }

            $user = User::query()->lockForUpdate()->findOrFail($link->user_id);

            $linkedElsewhere = CompanyUser::query()->active()
                ->where('user_id', $user->id)
                ->where('property_id', '<>', $company->property_id)
                ->exists();

            if ($user->deactivated_at !== null || ! $user->must_change_password || $linkedElsewhere) {
                return false;
            }

            $temporaryPassword = $this->generator->generate();

            $user->forceFill([
                'password' => $temporaryPassword,
                'temporary_password' => $temporaryPassword,
                'temporary_password_expires_at' => now()->addDays(CreateStaffWithTemporaryPassword::VALID_DAYS),
                'must_change_password' => true,
                'temporary_password_shared_at' => null,
            ])->save();

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.portal-temp-password-reset')
                ->withProperties(['property_id' => $company->property_id, 'user_id' => $user->id, 'email' => $user->email])
                ->log('Company portal temp password reset');

            return true;
        });
    }
}
