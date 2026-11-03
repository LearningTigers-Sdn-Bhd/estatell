<?php

namespace App\Enums;

/**
 * Where a nearby place stands with platform admins. Owners and guests never see it.
 */
enum NearbyPlaceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Approved => Tone::Success,
            self::Rejected => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending => in_array($status, [self::Approved, self::Rejected], true),
            self::Approved => $status === self::Rejected,
            self::Rejected => $status === self::Approved,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
