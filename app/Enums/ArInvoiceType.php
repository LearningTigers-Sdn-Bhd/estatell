<?php

namespace App\Enums;

/**
 * An AR document: an invoice for what a company owes, or a credit note that reduces one.
 */
enum ArInvoiceType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::CreditNote => __('Credit note'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
