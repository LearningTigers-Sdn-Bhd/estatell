<?php

namespace App\Enums;

enum PosShiftStatus: string
{
    case Open = 'open';
    case ClosingControl = 'closing_control';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::ClosingControl => __('Counting cash'),
            self::Closed => __('Closed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Open => Tone::Success,
            self::ClosingControl => Tone::Warning,
            self::Closed => Tone::Neutral,
        };
    }

    /**
     * Open to ClosingControl to Closed, with a way back from ClosingControl while the count is not final.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Open => $next === self::ClosingControl,
            self::ClosingControl => $next === self::Open || $next === self::Closed,
            self::Closed => false,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
