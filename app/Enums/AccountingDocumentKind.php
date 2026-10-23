<?php

namespace App\Enums;

/**
 * A company-billing document sent to the accounting software.
 */
enum AccountingDocumentKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::CreditNote => __('Credit note'),
            self::Receipt => __('Receipt'),
        };
    }
}
