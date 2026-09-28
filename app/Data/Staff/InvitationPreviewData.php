<?php

namespace App\Data\Staff;

use App\Models\StaffInvitation;
use Spatie\LaravelData\Data;

/**
 * What the invited person sees on the accept page.
 */
class InvitationPreviewData extends Data
{
    public function __construct(
        public string $email,
        public string $propertyName,
        public string $role,
    ) {}

    /**
     * Expects `property` and `role` loaded.
     */
    public static function fromModel(StaffInvitation $invitation): self
    {
        return new self(
            email: $invitation->email,
            propertyName: $invitation->property->name,
            role: $invitation->role->name,
        );
    }
}
