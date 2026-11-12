<?php

namespace App\Enums;

enum EInvoiceScenario: string
{
    case Folio = 'folio';
    case ArInvoice = 'ar_invoice';
    case Consolidation = 'consolidation';

    public function label(): string
    {
        return match ($this) {
            self::Folio => __('Guest Folio'),
            self::ArInvoice => __('AR City Ledger'),
            self::Consolidation => __('Monthly Consolidation'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $scenario): array => ['value' => $scenario->value, 'label' => $scenario->label()],
            self::cases(),
        );
    }
}
