<?php

namespace App\Enums;

/**
 * Which date the Payments report filters and sorts folio payments by: the posting (business) date, the default for a
 * cash-up, or the day the money came in (`payments.received_on`). City ledger payments always use `received_on`.
 * See docs/22-reports/01-core-reports.md.
 */
enum PaymentDateBasis: string
{
    case Posted = 'posted';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Posted => __('Posted'),
            self::Received => __('Received'),
        };
    }

    /**
     * The folio payment column the report filters and sorts by.
     */
    public function column(): string
    {
        return match ($this) {
            self::Posted => 'folio_lines.posting_date',
            self::Received => 'payments.received_on',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $basis): array => ['value' => $basis->value, 'label' => $basis->label()],
            self::cases(),
        );
    }
}
