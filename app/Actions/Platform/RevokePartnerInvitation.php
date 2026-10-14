<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokePartnerInvitation
{
    /**
     * Stop the link from working and free the prefix and the email. The Invited partner has no user and no
     * properties, so it is deleted; its invitation rows go with it. Returns false when the invitation is closed or
     * its partner is no longer Invited.
     */
    public function __invoke(User $revoker, PartnerInvitation $invitation): bool
    {
        return DB::transaction(function () use ($revoker, $invitation): bool {
            $partner = Partner::query()->lockForUpdate()->find($invitation->partner_id);
            $open = PartnerInvitation::query()->open()->lockForUpdate()->find($invitation->id);

            if ($partner === null || $open === null || $partner->status !== PartnerStatus::Invited) {
                return false;
            }

            activity()
                ->performedOn($partner)
                ->causedBy($revoker)
                ->event('partner.invitation-revoked')
                ->withProperties(['code_prefix' => $partner->code_prefix, 'email' => $open->email])
                ->log('Partner invitation revoked');

            DB::table('property_code_counters')->where('prefix', $partner->code_prefix)->delete();
            $partner->delete();

            return true;
        });
    }
}
