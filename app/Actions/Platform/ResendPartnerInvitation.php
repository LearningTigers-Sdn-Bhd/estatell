<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\User;
use App\Notifications\PartnerInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ResendPartnerInvitation
{
    /**
     * Send a fresh link with a new expiry. The old link stops working. Returns false when the invitation is closed
     * or its partner is no longer Invited.
     */
    public function __invoke(User $sender, PartnerInvitation $invitation): bool
    {
        $token = Str::random(48);

        $resent = DB::transaction(function () use ($sender, $invitation, $token): bool {
            $partner = Partner::query()->lockForUpdate()->find($invitation->partner_id);
            $open = PartnerInvitation::query()->open()->lockForUpdate()->find($invitation->id);

            if ($partner === null || $open === null || $partner->status !== PartnerStatus::Invited) {
                return false;
            }

            $open->update([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(InvitePartner::VALID_DAYS),
                'last_sent_at' => now(),
            ]);

            activity()
                ->performedOn($partner)
                ->causedBy($sender)
                ->event('partner.invitation-resent')
                ->withProperties(['code_prefix' => $partner->code_prefix, 'email' => $open->email])
                ->log('Partner invitation resent');

            return true;
        });

        if ($resent) {
            $invitation->refresh();
            Notification::route('mail', $invitation->email)->notify(new PartnerInvitationNotification($invitation, $token));
        }

        return $resent;
    }
}
