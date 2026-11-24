<?php

namespace App\Services\Pos;

use App\Enums\PermissionEnum;
use App\Models\User;

/**
 * The PIN length a staff user needs: 4 to 6 digits, and at least 6 for anyone who can void, refund, discount or manage POS.
 */
class PosPinPolicy
{
    /**
     * @var list<PermissionEnum>
     */
    public const ELEVATED = [PermissionEnum::PosVoid, PermissionEnum::PosRefund, PermissionEnum::PosDiscount, PermissionEnum::PosManage];

    public function holdsElevatedPermission(User $user): bool
    {
        return $this->withTeam($user, fn (): bool => array_any(
            self::ELEVATED,
            fn (PermissionEnum $permission): bool => $user->checkPermissionTo($permission->value),
        ));
    }

    public function minimumLength(User $user): int
    {
        return $this->holdsElevatedPermission($user) ? (int) config('pos.elevated_min_length') : (int) config('pos.pin_min_length');
    }

    public function isWellFormed(string $pin): bool
    {
        return preg_match('/^\d{'.(int) config('pos.pin_min_length').','.(int) config('pos.pin_max_length').'}$/', $pin) === 1;
    }

    /**
     * Permissions are team scoped, so check them inside the user's own property.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withTeam(User $user, callable $callback): mixed
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($user->property_id);

        try {
            return $callback();
        } finally {
            setPermissionsTeamId($previous);
        }
    }
}
