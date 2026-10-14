<?php

namespace App\Data\Platform;

use App\Models\PartnerInvitation;
use Spatie\LaravelData\Data;

/**
 * One open partner invitation on the admin Invited tab.
 */
class AdminPartnerInvitationRowData extends Data
{
    public function __construct(
        public int $id,
        public string $partnerName,
        public string $codePrefix,
        public string $email,
        public ?string $invitedBy,
        public string $lastSentAt,
        public string $expiresAt,
        public bool $expired,
    ) {}

    /**
     * Expects `partner` and `inviter` loaded.
     */
    public static function fromModel(PartnerInvitation $invitation): self
    {
        return new self(
            id: $invitation->id,
            partnerName: $invitation->partner->name,
            codePrefix: $invitation->partner->code_prefix,
            email: $invitation->email,
            invitedBy: $invitation->inviter?->name,
            lastSentAt: $invitation->last_sent_at->toDateString(),
            expiresAt: $invitation->expires_at->toDateString(),
            expired: $invitation->expires_at->isPast(),
        );
    }
}
