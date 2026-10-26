<?php

namespace App\Enums;

/**
 * The status of a row of Daily report › Charges: Moved when the line is part of a transfer
 * (`transfer_group_id`), else Reversal when it reverses another line (`reversal_of_id`), else Posted. Moved is checked
 * first, so a move shows as one Moved pair.
 */
enum ChargeStatus: string
{
    case Posted = 'posted';
    case Reversal = 'reversal';
    case Moved = 'moved';

    /**
     * The status of a ledger line from its transfer group and the line it reverses.
     */
    public static function forLine(?string $transferGroupId, ?int $reversalOfId): self
    {
        return match (true) {
            $transferGroupId !== null => self::Moved,
            $reversalOfId !== null => self::Reversal,
            default => self::Posted,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Posted => __('Posted'),
            self::Reversal => __('Reversal'),
            self::Moved => __('Moved'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Posted => Tone::Success,
            self::Reversal => Tone::Warning,
            self::Moved => Tone::Info,
        };
    }
}
