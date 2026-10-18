<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyUserStatus;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeclineCompanyLink
{
    /**
     * The signed-in user declines their own Pending link: it becomes Revoked, so the property can set the email
     * again. Returns false, and writes nothing, when the link is not the user's or is no longer Pending.
     */
    public function __invoke(User $user, CompanyUser $link): bool
    {
        return DB::transaction(function () use ($user, $link): bool {
            $link = CompanyUser::query()->with('company')->lockForUpdate()->findOrFail($link->id);

            if ($link->user_id !== $user->id || $link->status !== CompanyUserStatus::Pending) {
                return false;
            }

            $link->forceFill(['status' => CompanyUserStatus::Revoked, 'revoked_at' => now()])->save();
            $user->unreadNotifications()->where('data->company_user_id', $link->id)->update(['read_at' => now()]);

            activity()
                ->performedOn($link->company)
                ->causedBy($user)
                ->event('company-user.link-declined')
                ->withProperties(['property_id' => $link->property_id, 'company_user_id' => $link->id])
                ->log('Company link declined');

            return true;
        });
    }
}
