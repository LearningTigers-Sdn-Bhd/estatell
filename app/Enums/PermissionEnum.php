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
    case RoomsView = 'rooms.view';
    case RoomsManage = 'rooms.manage';
    case RoomsBlock = 'rooms.block';
    case NotificationManage = 'notification.manage';
    case RatesView = 'rates.view';
    case RatesManage = 'rates.manage';
    case RatesOverrideRestrictions = 'rates.override-restrictions';
    case GuestsView = 'guests.view';
    case GuestsManage = 'guests.manage';
    case GuestsBlacklist = 'guests.blacklist';
    case GuestsViewDocuments = 'guests.view-documents';

    public function label(): string
    {
        return match ($this) {
            self::SettingsManage => __('Manage property settings'),
            self::StaffView => __('View staff'),
            self::StaffManage => __('Manage staff'),
            self::RolesManage => __('Manage roles'),
            self::RoomsView => __('View rooms and room types'),
            self::RoomsManage => __('Manage rooms and room types'),
            self::RoomsBlock => __('Block rooms and beds'),
            self::NotificationManage => __('Manage notification settings'),
            self::RatesView => __('View the rate calendar'),
            self::RatesManage => __('Edit rates and restrictions'),
            self::RatesOverrideRestrictions => __('Book past rate restrictions'),
            self::GuestsView => __('View guests'),
            self::GuestsManage => __('Add and edit guests'),
            self::GuestsBlacklist => __('Blacklist guests'),
            self::GuestsViewDocuments => __('View and manage guest ID documents'),
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
            self::RoomsView, self::RoomsManage, self::RoomsBlock => __('Rooms'),
            self::NotificationManage => __('System'),
            self::RatesView, self::RatesManage, self::RatesOverrideRestrictions => __('Rates'),
            self::GuestsView, self::GuestsManage, self::GuestsBlacklist, self::GuestsViewDocuments => __('Guests'),
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
