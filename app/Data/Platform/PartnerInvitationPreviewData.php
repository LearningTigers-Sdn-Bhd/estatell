<?php

namespace App\Data\Platform;

use App\Models\PartnerInvitation;
use Spatie\LaravelData\Data;

/**
 * What the invited person sees on the partner accept page.
 */
class PartnerInvitationPreviewData extends Data
{
    public function __construct(
        public string $email,
        public string $partnerName,
    ) {}

    /**
     * Expects `partner` loaded.
     */
    public static function fromModel(PartnerInvitation $invitation): self
    {
        return new self(
            email: $invitation->email,
            partnerName: $invitation->partner->name,
        );
    }
}
