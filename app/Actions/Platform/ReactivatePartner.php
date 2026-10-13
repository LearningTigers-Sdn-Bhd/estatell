<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReactivatePartner
{
    /**
     * Move a Suspended partner back to Active. Returns false when the partner is not suspended.
     */
    public function __invoke(User $admin, Partner $partner): bool
    {
        return DB::transaction(function () use ($admin, $partner): bool {
            $partner = Partner::query()->lockForUpdate()->findOrFail($partner->id);
            $previousStatus = $partner->status;

            if ($previousStatus !== PartnerStatus::Suspended || ! $previousStatus->canTransitionTo(PartnerStatus::Active)) {
                return false;
            }

            $partner->update(['status' => PartnerStatus::Active, 'suspended_at' => null]);

            activity()
                ->performedOn($partner)
                ->causedBy($admin)
                ->event('partner.reactivated')
                ->withProperties(['from' => $previousStatus->value, 'to' => PartnerStatus::Active->value])
                ->log('Partner reactivated');

            return true;
        });
    }
}
