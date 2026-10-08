<?php

namespace App\Enums;

/**
 * The status of a row of the Payments report. It is the `PaymentStatus` of a folio payment, plus Voided for a city
 * ledger payment that was voided (an `ar_payments` row has no status of its own, only `voided_at`).
 */
enum PaymentReportStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Voided = 'voided';

    public static function fromPaymentStatus(PaymentStatus $status): self
    {
        return self::from($status->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
            self::Voided => __('Voided'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Completed => Tone::Success,
            self::Failed => Tone::Danger,
            self::Voided => Tone::Neutral,
        };
    }
}
