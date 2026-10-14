<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetPartnerCanCreateProperties
{
    /**
     * Allow or stop an Active partner from creating properties themselves, and log the change. Setting the same
     * value again does nothing. Returns false when the partner is not Active.
     */
    public function __invoke(User $admin, Partner $partner, bool $allowed): bool
    {
        return DB::transaction(function () use ($admin, $partner, $allowed): bool {
            $partner = Partner::query()->lockForUpdate()->findOrFail($partner->id);

            if ($partner->status !== PartnerStatus::Active) {
                return false;
            }

            if ($partner->can_create_properties === $allowed) {
                return true;
            }

            $partner->update(['can_create_properties' => $allowed]);

            activity()
                ->performedOn($partner)
                ->causedBy($admin)
                ->event($allowed ? 'partner.create-properties-allowed' : 'partner.create-properties-disallowed')
                ->log($allowed ? 'Partner may create properties' : 'Partner may not create properties');

            return true;
        });
    }
}
