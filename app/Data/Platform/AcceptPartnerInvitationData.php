<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * The login a partner creates from an invitation. The password is set on the user and must never be logged or
 * passed to an activity log.
 */
class AcceptPartnerInvitationData extends Data
{
    public function __construct(
        public string $name,
        public string $password,
    ) {}
}
