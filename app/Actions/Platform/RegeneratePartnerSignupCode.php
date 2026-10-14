<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\User;
use App\Services\Platform\PartnerSignupCode;
use Illuminate\Support\Facades\DB;

class RegeneratePartnerSignupCode
{
    public function __construct(private PartnerSignupCode $signupCode) {}

    /**
     * Issue a new signup code for an Active partner. The old link stops working at once. The codes are not logged.
     * Returns false when the partner is not Active.
     */
    public function __invoke(User $user, Partner $partner): bool
    {
        return DB::transaction(function () use ($user, $partner): bool {
            $partner = Partner::query()->lockForUpdate()->findOrFail($partner->id);

            if ($partner->status !== PartnerStatus::Active) {
                return false;
            }

            $partner->forceFill(['signup_code' => $this->signupCode->generate()])->save();

            activity()
                ->performedOn($partner)
                ->causedBy($user)
                ->event('partner.signup-link-regenerated')
                ->log('Partner signup link regenerated');

            return true;
        });
    }
}
