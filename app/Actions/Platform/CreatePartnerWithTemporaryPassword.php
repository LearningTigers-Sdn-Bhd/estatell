<?php

namespace App\Actions\Platform;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Data\Platform\NewPartnerData;
use App\Enums\PartnerStatus;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePartnerWithTemporaryPassword
{
    public function __construct(
        private CreatePartner $createPartner,
        private TemporaryPasswordGenerator $generator,
    ) {}

    /**
     * Create an Active partner and its one login, which signs in with a generated temp password and must then set
     * its own. The admin vouches for the person, so the email is marked verified. The request has already checked
     * the prefix and that the email has no account.
     */
    public function __invoke(User $admin, NewPartnerData $data): User
    {
        return DB::transaction(function () use ($admin, $data): User {
            $partner = ($this->createPartner)($data->partnerName, $data->codePrefix, PartnerStatus::Active);
            $temporaryPassword = $this->generator->generate();

            $user = new User(['name' => $data->name, 'email' => Str::lower(trim($data->email)), 'password' => $temporaryPassword]);
            $user->forceFill([
                'partner_id' => $partner->id,
                'email_verified_at' => now(),
                'temporary_password' => $temporaryPassword,
                'temporary_password_expires_at' => now()->addDays(CreateStaffWithTemporaryPassword::VALID_DAYS),
                'must_change_password' => true,
            ])->save();

            $user->setRelation('partner', $partner);

            activity()
                ->performedOn($partner)
                ->causedBy($admin)
                ->event('partner.created-with-temp-password')
                ->withProperties(['code_prefix' => $partner->code_prefix, 'user_id' => $user->id, 'email' => $user->email])
                ->log('Partner created with a temp password');

            return $user;
        });
    }
}
