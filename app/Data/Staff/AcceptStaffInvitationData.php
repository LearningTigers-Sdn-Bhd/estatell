<?php

namespace App\Data\Staff;

use Spatie\LaravelData\Data;

/**
 * The login a staff member creates from an invitation. The password is set on the user and must never be logged or
 * passed to an activity log.
 */
class AcceptStaffInvitationData extends Data
{
    public function __construct(
        public string $name,
        public string $password,
    ) {}
}
