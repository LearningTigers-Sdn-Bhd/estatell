<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyUserStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Notifications\CompanyLinkInvitation;
use Illuminate\Support\Facades\DB;

class ResendCompanyLinkInvitation
{
    /**
     * Send the company's Pending link invitation again, in-app and by mail, and set `last_sent_at`.
     *
     * Returns false when the company has no Pending link, or the user's login was deactivated.
     */
    public function __invoke(User $actor, Company $company): bool
    {
        return DB::transaction(function () use ($actor, $company): bool {
            $link = CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('status', CompanyUserStatus::Pending->value)
                ->with('user')
                ->lockForUpdate()
                ->first();

            if (! $link instanceof CompanyUser || $link->user->deactivated_at !== null) {
                return false;
            }

            $link->forceFill(['last_sent_at' => now()])->save();
            $link->user->notify(new CompanyLinkInvitation($link->id, $company->property->name, $company->name));

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.portal-invitation-resent')
                ->withProperties(['property_id' => $company->property_id, 'user_id' => $link->user_id, 'email' => $link->user->email])
                ->log('Company portal invitation resent');

            return true;
        });
    }
}
