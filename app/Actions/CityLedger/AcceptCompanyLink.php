<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyLinkRefusal;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserStatus;
use App\Enums\PropertyStatus;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AcceptCompanyLink
{
    /**
     * Postgres code for a unique index violation.
     */
    private const string UNIQUE_VIOLATION = '23505';

    /**
     * The signed-in user activates their own Pending link. Returns null on success, or the refusal. A refusal writes
     * and logs nothing. Refused when the link is not Pending, the company is Suspended, the property is not Live, the
     * login is deactivated, or another open link already holds the company or this user at the property. The caller
     * has checked that the link belongs to the user.
     */
    public function __invoke(User $user, CompanyUser $link): ?CompanyLinkRefusal
    {
        try {
            return DB::transaction(function () use ($user, $link): ?CompanyLinkRefusal {
                $link = CompanyUser::query()->with('company', 'property')->lockForUpdate()->findOrFail($link->id);

                $refusal = match (true) {
                    $link->user_id !== $user->id || $link->status !== CompanyUserStatus::Pending => CompanyLinkRefusal::NotPending,
                    $user->deactivated_at !== null => CompanyLinkRefusal::LoginNotAvailable,
                    $link->company->status === CompanyStatus::Suspended => CompanyLinkRefusal::CompanySuspended,
                    $link->property->status !== PropertyStatus::Live => CompanyLinkRefusal::PropertyNotLive,
                    $this->otherOpenLinkExists($link) => CompanyLinkRefusal::AlreadyLinked,
                    default => null,
                };

                if ($refusal !== null) {
                    return $refusal;
                }

                $link->forceFill(['status' => CompanyUserStatus::Active, 'accepted_at' => now()])->save();
                $this->markInvitationRead($user, $link);

                activity()
                    ->performedOn($link->company)
                    ->causedBy($user)
                    ->event('company-user.link-accepted')
                    ->withProperties(['property_id' => $link->property_id, 'company_user_id' => $link->id])
                    ->log('Company link accepted');

                return null;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== self::UNIQUE_VIOLATION) {
                throw $exception;
            }

            return CompanyLinkRefusal::AlreadyLinked;
        }
    }

    private function otherOpenLinkExists(CompanyUser $link): bool
    {
        return CompanyUser::query()->open()
            ->whereKeyNot($link->id)
            ->where(fn ($query) => $query
                ->where('company_id', $link->company_id)
                ->orWhere(fn ($same) => $same->where('property_id', $link->property_id)->where('user_id', $link->user_id)))
            ->exists();
    }

    private function markInvitationRead(User $user, CompanyUser $link): void
    {
        $user->unreadNotifications()->where('data->company_user_id', $link->id)->update(['read_at' => now()]);
    }
}
