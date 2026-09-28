<?php

namespace App\Enums;

/**
 * Every staff permission. Each module adds its own cases and maps them in DefaultRoleEnum.
 * SyncPermissions turns new cases into permission rows. See docs/03-staff/02-roles-and-permissions.md.
 */
enum PermissionEnum: string
{
    case SettingsManage = 'settings.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';

    public function label(): string
    {
        return match ($this) {
            self::SettingsManage => __('Manage property settings'),
            self::StaffView => __('View staff'),
            self::StaffManage => __('Manage staff'),
            self::RolesManage => __('Manage roles'),
        };
    }

    /**
     * The heading the permission sits under on the role page.
     */
    public function group(): string
    {
        return match ($this) {
            self::SettingsManage => __('Property'),
            self::StaffView, self::StaffManage, self::RolesManage => __('Team'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $permission): array => ['value' => $permission->value, 'label' => $permission->label()],
            self::cases(),
        );
    }
}
