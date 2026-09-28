<?php

namespace App\Data\Staff;

use App\Models\User;
use Spatie\LaravelData\Data;

class StaffMemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $role,
        public bool $isYou,
        public string $joinedAt,
        public ?string $deactivatedAt,
    ) {}

    /**
     * Expects `roles` loaded for the current permission team.
     */
    public static function fromModel(User $user, User $viewer): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->roles->first()?->name,
            isYou: $user->is($viewer),
            joinedAt: $user->created_at->toDateString(),
            deactivatedAt: $user->deactivated_at?->toDateString(),
        );
    }
}
