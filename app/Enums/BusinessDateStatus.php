<?php

namespace App\Enums;

enum BusinessDateStatus: string
{
    case Open = 'open';
    case Auditing = 'auditing';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Auditing => __('Auditing'),
            self::Closed => __('Closed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Open => Tone::Success,
            self::Auditing => Tone::Info,
            self::Closed => Tone::Neutral,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Open => [self::Auditing],
            self::Auditing => [self::Closed],
            self::Closed => [],
        }, true);
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
