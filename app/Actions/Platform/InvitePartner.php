<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\PartnerInvitation;
use App\Models\User;
use App\Notifications\PartnerInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InvitePartner
{
    /**
     * Days an invitation link stays valid after it is sent or resent.
     */
    public const int VALID_DAYS = 7;

    public function __construct(private CreatePartner $createPartner) {}

    /**
     * Reserve the prefix with an Invited partner, then send the invitation link. The request has already checked
     * the prefix, and that the email has no account and no open invitation.
     */
    public function __invoke(User $admin, string $name, string $prefix, string $email): PartnerInvitation
    {
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($admin, $name, $prefix, $email, $token): PartnerInvitation {
            $partner = ($this->createPartner)($name, $prefix, PartnerStatus::Invited);

            $invitation = $partner->invitations()->create([
                'email' => Str::lower($email),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'last_sent_at' => now(),
                'invited_by' => $admin->id,
            ]);

            activity()
                ->performedOn($partner)
                ->causedBy($admin)
                ->event('partner.invited')
                ->withProperties(['code_prefix' => $partner->code_prefix, 'email' => $invitation->email])
                ->log('Partner invited');

            return $invitation;
        });

        Notification::route('mail', $invitation->email)->notify(new PartnerInvitationNotification($invitation, $token));

        return $invitation;
    }
}
