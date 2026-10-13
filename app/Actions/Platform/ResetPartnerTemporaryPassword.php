<?php

namespace App\Actions\Platform;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Enums\PartnerStatus;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\DB;

class ResetPartnerTemporaryPassword
{
    public function __construct(private TemporaryPasswordGenerator $generator) {}

    /**
     * Replace a partner user's password with a new temp password that they must change after signing in.
     * The password changes, so `auth.session` signs them out everywhere.
     *
     * Returns false for a user without a partner and for a Suspended partner (reactivate it first).
     */
    public function __invoke(User $admin, User $partnerUser): bool
    {
        return DB::transaction(function () use ($admin, $partnerUser): bool {
            $partnerUser = User::query()->lockForUpdate()->findOrFail($partnerUser->id);

            if ($partnerUser->partner_id === null || $partnerUser->partner->status === PartnerStatus::Suspended) {
                return false;
            }

            $temporaryPassword = $this->generator->generate();

            $partnerUser->forceFill([
                'password' => $temporaryPassword,
                'temporary_password' => $temporaryPassword,
                'temporary_password_expires_at' => now()->addDays(CreateStaffWithTemporaryPassword::VALID_DAYS),
                'must_change_password' => true,
            ])->save();

            activity()
                ->performedOn($partnerUser->partner)
                ->causedBy($admin)
                ->event('partner.temp-password-reset')
                ->withProperties(['user_id' => $partnerUser->id, 'email' => $partnerUser->email])
                ->log('Partner temp password reset');

            return true;
        });
    }
}
