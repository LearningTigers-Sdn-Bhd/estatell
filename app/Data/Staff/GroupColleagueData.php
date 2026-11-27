<?php

namespace App\Data\Staff;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * A staff member of another property in the group, offered in Settings › Staff › Add from another property.
 * See docs/03-staff/04-property-groups.md.
 */
class GroupColleagueData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $homeProperty,
    ) {}

    /**
     * Expects the user's `property` loaded.
     */
    public static function fromUser(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            homeProperty: (string) $user->property?->name,
        );
    }
}
