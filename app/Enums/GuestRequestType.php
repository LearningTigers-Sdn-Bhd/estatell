<?php

namespace App\Enums;

enum GuestRequestType: string
{
    case Housekeeping = 'housekeeping';
    case Maintenance = 'maintenance';
    case Complaint = 'complaint';
    case Other = 'other';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Housekeeping => __('Housekeeping'),
            self::Maintenance => __('Maintenance'),
            self::Complaint => __('Complaint'),
            self::Other => __('Other'),
            self::Refund => __('Refund'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Housekeeping => Tone::Info,
            self::Maintenance => Tone::Warning,
            self::Complaint => Tone::Danger,
            self::Other => Tone::Neutral,
            self::Refund => Tone::Warning,
        };
    }

    /**
     * Work in a room needs a guest who is in the house. A complaint or another request also comes from a guest who left.
     */
    public function needsInHouseStay(): bool
    {
        return $this === self::Housekeeping || $this === self::Maintenance;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
