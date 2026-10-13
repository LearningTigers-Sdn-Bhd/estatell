<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SuspendPartner
{
    /**
     * Suspend an Active partner. Its users and properties are not touched. Returns false when the partner
     * cannot be suspended.
     */
    public function __invoke(User $admin, Partner $partner): bool
    {
        return DB::transaction(function () use ($admin, $partner): bool {
            $partner = Partner::query()->lockForUpdate()->findOrFail($partner->id);
            $previousStatus = $partner->status;

            if (! $previousStatus->canTransitionTo(PartnerStatus::Suspended)) {
                return false;
            }

            $partner->update(['status' => PartnerStatus::Suspended, 'suspended_at' => now()]);

            activity()
                ->performedOn($partner)
                ->causedBy($admin)
                ->event('partner.suspended')
                ->withProperties(['from' => $previousStatus->value, 'to' => PartnerStatus::Suspended->value])
                ->log('Partner suspended');

            return true;
        });
    }
}
