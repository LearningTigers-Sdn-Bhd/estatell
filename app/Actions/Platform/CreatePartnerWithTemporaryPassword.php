<?php

namespace App\Actions\Platform;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
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
     *
     * @param  array{partner_name: string, code_prefix: string, name: string, email: string}  $input
     */
    public function __invoke(User $admin, array $input): User
    {
        return DB::transaction(function () use ($admin, $input): User {
            $partner = ($this->createPartner)($input['partner_name'], $input['code_prefix'], PartnerStatus::Active);
            $temporaryPassword = $this->generator->generate();

            $user = new User(['name' => $input['name'], 'email' => Str::lower(trim($input['email'])), 'password' => $temporaryPassword]);
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
