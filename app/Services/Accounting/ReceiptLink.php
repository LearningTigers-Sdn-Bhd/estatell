<?php

namespace App\Services\Accounting;

/**
 * Part of a receipt matched to one invoice already sent.
 */
final readonly class ReceiptLink
{
    public function __construct(public DocumentRef $invoice, public string $amount) {}
}
