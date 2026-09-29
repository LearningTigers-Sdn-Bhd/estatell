<?php

namespace App\Enums;

/**
 * Roles seeded for every property, with their default permissions. The Owner role always holds every permission.
 */
enum DefaultRoleEnum: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case FrontDesk = 'front_desk';
    case Housekeeping = 'housekeeping';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Manager => __('Manager'),
            self::FrontDesk => __('Front desk'),
            self::Housekeeping => __('Housekeeping'),
            self::Finance => __('Finance'),
        };
    }

    /**
     * @return list<PermissionEnum>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => PermissionEnum::cases(),
            self::Manager => [PermissionEnum::SettingsManage, PermissionEnum::StaffView, PermissionEnum::StaffManage, PermissionEnum::RoomsView, PermissionEnum::RoomsManage, PermissionEnum::RoomsBlock],
            self::FrontDesk => [PermissionEnum::RoomsBlock],
            self::Housekeeping, self::Finance => [],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role): array => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
