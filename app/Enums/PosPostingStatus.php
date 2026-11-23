<?php

namespace App\Enums;

enum PosPostingStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';
    case PendingReview = 'pending_review';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Posted => __('Posted'),
            self::Reversed => __('Reversed'),
            self::PendingReview => __('Needs review'),
            self::Ignored => __('Ignored'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Posted => 'success',
            self::Reversed, self::Ignored => 'neutral',
            self::PendingReview => 'warning',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::PendingReview => in_array($to, [self::Posted, self::Ignored, self::Reversed], true),
            self::Posted => $to === self::Reversed,
            self::Reversed, self::Ignored => false,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
