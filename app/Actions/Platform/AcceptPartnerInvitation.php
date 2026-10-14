<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptPartnerInvitation
{
    /**
     * Create the partner's login from an open invitation, make the partner Active and close the invitation.
     *
     * Accepting proves the email, so it is marked verified. Returns false when the invitation is closed or expired,
     * the partner is no longer Invited, or the email got an account after the invitation was sent.
     *
     * @param  array{name: string, password: string}  $input
     */
    public function __invoke(PartnerInvitation $invitation, array $input): User|false
    {
        return DB::transaction(function () use ($invitation, $input): User|false {
            $partner = Partner::query()->lockForUpdate()->find($invitation->partner_id);
            $open = PartnerInvitation::query()->open()->lockForUpdate()->find($invitation->id);

            if ($partner === null || $open === null || $open->expires_at->isPast() || $partner->status !== PartnerStatus::Invited) {
                return false;
            }

            if (User::query()->whereRaw('lower(email) = ?', [$open->email])->exists()) {
                return false;
            }

            $user = new User(['name' => $input['name'], 'email' => $open->email, 'password' => $input['password']]);
            $user->forceFill(['partner_id' => $partner->id, 'email_verified_at' => now()])->save();

            $partner->update(['status' => PartnerStatus::Active]);
            $open->update(['accepted_at' => now()]);

            activity()
                ->performedOn($partner)
                ->causedBy($user)
                ->event('partner.invitation-accepted')
                ->withProperties(['code_prefix' => $partner->code_prefix, 'email' => $open->email, 'user_id' => $user->id])
                ->log('Partner invitation accepted');

            return $user;
        });
    }
}
