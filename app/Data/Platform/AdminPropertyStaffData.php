<?php

namespace App\Data\Platform;

use App\Enums\DefaultRoleEnum;
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
        public bool $canSendSetupLink,
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
            canSendSetupLink: $user->email_verified_at === null
                && $user->roles->first()?->default_role === DefaultRoleEnum::Owner,
            createdAt: $user->created_at->toDateString(),
        );
    }

    /**
     * The row a partner sees: the same columns, but never the setup-email action.
     */
    public static function forPartner(User $user): self
    {
        $row = self::fromModel($user);
        $row->canSendSetupLink = false;

        return $row;
    }
}
