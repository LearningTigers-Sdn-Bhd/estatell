<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;

it('maps the Native POS permissions to the default roles', function (PermissionEnum $permission, array $roles) {
    foreach (DefaultRoleEnum::cases() as $role) {
        expect(in_array($permission, $role->permissions(), true))->toBe(in_array($role, $roles, true), "{$permission->value} on {$role->value}");
    }
})->with([
    'sell' => [PermissionEnum::PosSell, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk]],
    'shift' => [PermissionEnum::PosShift, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk]],
    'kds' => [PermissionEnum::PosKds, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk]],
    'void' => [PermissionEnum::PosVoid, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager]],
    'discount' => [PermissionEnum::PosDiscount, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager]],
    'refund' => [PermissionEnum::PosRefund, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager]],
    'menu' => [PermissionEnum::PosMenu, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager]],
    'inventory' => [PermissionEnum::PosInventory, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager]],
    'hardware' => [PermissionEnum::PosHardware, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager]],
    'reports' => [PermissionEnum::PosReports, [DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::Finance]],
]);
