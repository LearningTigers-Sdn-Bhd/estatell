<?php

namespace App\Enums;

/**
 * The status of a folio. Closing and reopening are Actions checked by `canTransitionTo()`.
 * See docs/13-folio/01-folios-and-lines.md.
 */
enum FolioStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Closed => __('Closed'),
            self::Voided => __('Voided'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Open => Tone::Info,
            self::Closed => Tone::Neutral,
            self::Voided => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Open => [self::Closed, self::Voided],
            self::Closed => [self::Open],
            self::Voided => [],
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
