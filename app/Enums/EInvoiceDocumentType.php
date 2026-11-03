<?php

namespace App\Enums;

enum EInvoiceDocumentType: string
{
    case Invoice = '01';
    case CreditNote = '02';
    case DebitNote = '03';
    case RefundNote = '04';
    case SelfBilledInvoice = '11';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Standard Invoice (01)'),
            self::CreditNote => __('Credit Note (02)'),
            self::DebitNote => __('Debit Note (03)'),
            self::RefundNote => __('Refund Note (04)'),
            self::SelfBilledInvoice => __('Self-Billed Invoice (11)'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::CreditNote => __('Credit Note'),
            self::DebitNote => __('Debit Note'),
            self::RefundNote => __('Refund Note'),
            self::SelfBilledInvoice => __('Self-Billed'),
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
