<?php

namespace App\Enums;

enum AccountingDocumentStatus: string
{
    case Pending = 'pending';
    case Pushed = 'pushed';
    case Failed = 'failed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Pushed => __('Sent'),
            self::Failed => __('Failed'),
            self::Voided => __('Voided'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Info,
            self::Pushed => Tone::Success,
            self::Failed => Tone::Danger,
            self::Voided => Tone::Neutral,
        };
    }

    /**
     * A sent document can be voided or replaced (a receipt whose allocations changed), a voided one can be sent again, and a
     * failed one can be tried again.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Pushed, self::Failed], true),
            self::Failed => in_array($next, [self::Pushed, self::Failed, self::Voided], true),
            self::Pushed => in_array($next, [self::Pushed, self::Failed, self::Voided], true),
            self::Voided => in_array($next, [self::Pushed, self::Failed], true),
        };
    }
}
