<?php

namespace App\Enums;

enum EInvoiceStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Pending => __('Preparing'),
            self::Submitted => __('Submitted'),
            self::Valid => __('Valid'),
            self::Invalid => __('Rejected'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft => Tone::Neutral,
            self::Pending => Tone::Warning,
            self::Submitted => Tone::Info,
            self::Valid => Tone::Success,
            self::Invalid => Tone::Danger,
            self::Cancelled => Tone::Neutral,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Draft => in_array($status, [self::Pending, self::Submitted, self::Cancelled], true),
            self::Pending => in_array($status, [self::Submitted, self::Invalid, self::Cancelled], true),
            self::Submitted => in_array($status, [self::Valid, self::Invalid, self::Cancelled], true),
            self::Valid => $status === self::Cancelled,
            self::Invalid => in_array($status, [self::Pending, self::Submitted], true),
            self::Cancelled => false,
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
