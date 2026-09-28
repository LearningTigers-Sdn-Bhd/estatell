<?php

namespace App\Data\Platform;

use App\Models\User;
use Spatie\LaravelData\Data;

class AdminPropertyStaffData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $role,
        public bool $verified,
        public bool $deactivated,
        public string $createdAt,
    ) {}

    /**
     * Expects `roles` loaded for the property's permission team.
     */
    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->roles->first()?->name,
            verified: $user->email_verified_at !== null,
            deactivated: $user->deactivated_at !== null,
            createdAt: $user->created_at->toDateString(),
        );
    }
}
