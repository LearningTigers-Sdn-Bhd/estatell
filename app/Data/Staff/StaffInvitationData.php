<?php

namespace App\Data\Staff;

use App\Models\StaffInvitation;
use Spatie\LaravelData\Data;

class StaffInvitationData extends Data
{
    public function __construct(
        public int $id,
        public string $email,
        public string $role,
        public ?string $invitedBy,
        public string $lastSentAt,
        public string $expiresAt,
        public bool $expired,
    ) {}

    /**
     * Expects `role` and `inviter` loaded.
     */
    public static function fromModel(StaffInvitation $invitation): self
    {
        return new self(
            id: $invitation->id,
            email: $invitation->email,
            role: $invitation->role->name,
            invitedBy: $invitation->inviter?->name,
            lastSentAt: $invitation->last_sent_at->toDateString(),
            expiresAt: $invitation->expires_at->toDateString(),
            expired: $invitation->expires_at->isPast(),
        );
    }
}
