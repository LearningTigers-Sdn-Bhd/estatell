<?php

namespace App\Enums;

enum PosReconciliationStatus: string
{
    case Verified = 'verified';
    case PendingVerification = 'pending_verification';

    public function label(): string
    {
        return match ($this) {
            self::Verified => __('Verified'),
            self::PendingVerification => __('Waiting to be verified'),
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
