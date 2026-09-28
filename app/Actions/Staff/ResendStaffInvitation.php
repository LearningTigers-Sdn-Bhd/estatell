<?php

namespace App\Actions\Staff;

use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class ResendStaffInvitation
{
    /**
     * Send a fresh link with a new expiry. The old link stops working. Returns false when the invitation is closed.
     */
    public function __invoke(User $sender, StaffInvitation $invitation): bool
    {
        $token = Str::random(48);

        $resent = DB::transaction(function () use ($sender, $invitation, $token): bool {
            $invitation = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                return false;
            }

            $invitation->update([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(InviteStaff::VALID_DAYS),
                'last_sent_at' => now(),
            ]);

            activity()
                ->performedOn($invitation)
                ->causedBy($sender)
                ->event('staff.invitation-resent')
                ->withProperties(['property_id' => $invitation->property_id, 'email' => $invitation->email])
                ->log('Staff invitation resent');

            return true;
        });

        if ($resent) {
            Notification::route('mail', $invitation->email)->notify(new StaffInvitationNotification($invitation->refresh(), $token));
        }

        return $resent;
    }
}
