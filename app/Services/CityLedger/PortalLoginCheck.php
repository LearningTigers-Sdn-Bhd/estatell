<?php

namespace App\Services\CityLedger;

use App\Enums\PortalLoginRefusal;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PartnerInvitation;
use App\Models\Property;
use App\Models\StaffInvitation;
use App\Models\User;

/**
 * Reads whether an email may become the portal login of a company at a property. Used by the save Action and by the
 * form requests, so a refusal shows on its field. It writes nothing.
 */
class PortalLoginCheck
{
    /**
     * The existing user of the email, when there is one.
     */
    public function userFor(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
    }

    /**
     * Why the email cannot be the login of the company, or null when it can. A new company has no id yet: pass null.
     */
    public function refusal(Property $property, ?Company $company, string $email): ?PortalLoginRefusal
    {
        $user = $this->userFor($email);

        if ($user === null) {
            return $this->hasOpenInvitation($email) ? PortalLoginRefusal::OtherKindOfAccount : null;
        }

        if (! $user->is_company_user) {
            return PortalLoginRefusal::OtherKindOfAccount;
        }

        if ($user->deactivated_at !== null) {
            return PortalLoginRefusal::NotAvailable;
        }

        $takenHere = CompanyUser::query()
            ->open()
            ->where('property_id', $property->id)
            ->where('user_id', $user->id)
            ->when($company instanceof Company, fn ($query) => $query->where('company_id', '<>', $company->id))
            ->exists();

        return $takenHere ? PortalLoginRefusal::TakenByOtherCompany : null;
    }

    private function hasOpenInvitation(string $email): bool
    {
        $lower = mb_strtolower($email);

        return StaffInvitation::query()->whereRaw('lower(email) = ?', [$lower])->whereNull('accepted_at')->whereNull('revoked_at')->exists()
            || PartnerInvitation::query()->whereRaw('lower(email) = ?', [$lower])->whereNull('accepted_at')->whereNull('revoked_at')->exists();
    }
}
