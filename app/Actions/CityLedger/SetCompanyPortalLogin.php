<?php

namespace App\Actions\CityLedger;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserStatus;
use App\Enums\PortalLoginOutcome;
use App\Enums\PortalLoginRefusal;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Notifications\CompanyLinkInvitation;
use App\Services\CityLedger\PortalLoginCheck;
use App\Services\Staff\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\DB;

class SetCompanyPortalLogin
{
    public function __construct(
        private PortalLoginCheck $check,
        private TemporaryPasswordGenerator $generator,
    ) {}

    /**
     * Set, change or clear the company's one portal login. Empty clears it and revokes an open link. The same email
     * does nothing. A new email makes a company user (named after the company) with a temp password and an Active
     * link. An existing company user gets a Pending link and a `CompanyLinkInvitation`. A staff, admin, partner or
     * deactivated account, or a login another company here already uses, is refused before the old link is touched.
     * A Suspended company keeps its link and refuses a change.
     *
     * Passwords are never logged. Callers run it inside their own transaction and roll back on a refusal.
     */
    public function __invoke(User $actor, Company $company, ?string $email): CompanyPortalLoginResult
    {
        $email = filled($email) ? mb_strtolower(trim((string) $email)) : null;

        return DB::transaction(function () use ($actor, $company, $email): CompanyPortalLoginResult {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $open = $company->companyUsers()->open()->with('user')->first();

            if (($open?->user->email) === $email) {
                return new CompanyPortalLoginResult(PortalLoginOutcome::Unchanged, $open);
            }

            if ($company->status === CompanyStatus::Suspended) {
                return $this->refused(PortalLoginRefusal::CompanySuspended);
            }

            if ($email === null) {
                $this->revoke($actor, $company, $open);

                return new CompanyPortalLoginResult(PortalLoginOutcome::Removed);
            }

            $refusal = $this->check->refusal($company->property, $company, $email);

            if ($refusal !== null) {
                return $this->refused($refusal);
            }

            if ($open instanceof CompanyUser) {
                $this->close($open);
            }

            $user = $this->check->userFor($email);

            $result = $user instanceof User
                ? $this->invite($actor, $company, $user)
                : $this->createLogin($actor, $company, $email);

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.portal-login-set')
                ->withProperties([
                    'property_id' => $company->property_id,
                    'email' => $email,
                    'user_id' => $result->link?->user_id,
                    'outcome' => $result->outcome->value,
                ])
                ->log('Company portal login set');

            return $result;
        });
    }

    private function refused(PortalLoginRefusal $refusal): CompanyPortalLoginResult
    {
        return new CompanyPortalLoginResult(PortalLoginOutcome::Refused, refusal: $refusal);
    }

    private function revoke(User $actor, Company $company, ?CompanyUser $open): void
    {
        if (! $open instanceof CompanyUser) {
            return;
        }

        $this->close($open);

        activity()
            ->performedOn($company)
            ->causedBy($actor)
            ->event('company.portal-login-removed')
            ->withProperties(['property_id' => $company->property_id, 'email' => $open->user->email, 'user_id' => $open->user_id])
            ->log('Company portal login removed');
    }

    private function close(CompanyUser $link): void
    {
        $link->forceFill(['status' => CompanyUserStatus::Revoked, 'revoked_at' => now()])->save();
    }

    private function createLogin(User $actor, Company $company, string $email): CompanyPortalLoginResult
    {
        $temporaryPassword = $this->generator->generate();

        $user = new User(['name' => $company->name, 'email' => $email, 'password' => $temporaryPassword]);
        $user->forceFill([
            'is_company_user' => true,
            'temporary_password' => $temporaryPassword,
            'temporary_password_expires_at' => now()->addDays(CreateStaffWithTemporaryPassword::VALID_DAYS),
            'must_change_password' => true,
        ])->save();

        $link = CompanyUser::query()->create([
            'property_id' => $company->property_id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => CompanyUserStatus::Active,
            'invited_by' => $actor->id,
            'accepted_at' => now(),
        ]);

        return new CompanyPortalLoginResult(PortalLoginOutcome::Created, $link->setRelation('user', $user));
    }

    private function invite(User $actor, Company $company, User $user): CompanyPortalLoginResult
    {
        $link = CompanyUser::query()->create([
            'property_id' => $company->property_id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => CompanyUserStatus::Pending,
            'invited_by' => $actor->id,
            'last_sent_at' => now(),
        ]);

        $user->notify(new CompanyLinkInvitation($link->id, $company->property->name, $company->name));

        return new CompanyPortalLoginResult(PortalLoginOutcome::Invited, $link->setRelation('user', $user));
    }
}
