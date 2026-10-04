<?php

namespace App\Data\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\User;
use Spatie\LaravelData\Data;

class StaffMemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?int $roleId,
        public ?string $role,
        public bool $isOwner,
        public bool $isYou,
        public string $joinedAt,
        public ?string $deactivatedAt,
        public ?string $temporaryPasswordExpiresAt,
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
            roleId: $user->roles->first()?->id,
            role: $user->roles->first()?->name,
            isOwner: $user->roles->first()?->default_role === DefaultRoleEnum::Owner,
            isYou: $user->is($viewer),
            joinedAt: $user->created_at->toDateString(),
            deactivatedAt: $user->deactivated_at?->toDateString(),
            temporaryPasswordExpiresAt: $user->temporary_password_expires_at?->toIso8601String(),
        );
    }
}
