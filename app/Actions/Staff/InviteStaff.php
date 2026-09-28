<?php

namespace App\Actions\Staff;

use App\Models\Property;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteStaff
{
    /**
     * Days an invitation link stays valid after it is sent or resent.
     */
    public const int VALID_DAYS = 7;

    /**
     * Invite an email to join the property with a role and send the invitation link.
     *
     * The request has already checked that the email has no account and no open invitation here.
     */
    public function __invoke(Property $property, User $inviter, string $email, Role $role): StaffInvitation
    {
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($property, $inviter, $email, $role, $token): StaffInvitation {
            $invitation = $property->staffInvitations()->create([
                'email' => Str::lower($email),
                'role_id' => $role->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'last_sent_at' => now(),
                'invited_by' => $inviter->id,
            ]);

            activity()
                ->performedOn($invitation)
                ->causedBy($inviter)
                ->event('staff.invited')
                ->withProperties(['property_id' => $property->id, 'email' => $invitation->email, 'role' => $role->name])
                ->log('Staff invited');

            return $invitation;
        });

        Notification::route('mail', $invitation->email)->notify(new StaffInvitationNotification($invitation, $token));

        return $invitation;
    }
}
