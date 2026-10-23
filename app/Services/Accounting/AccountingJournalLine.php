<?php

namespace App\Services\Accounting;

/**
 * One line of a journal. `debit` and `credit` are decimal strings with 2 decimals, and exactly one of them is not zero.
 */
final readonly class AccountingJournalLine
{
    public function __construct(
        public string $accountCode,
        public string $debit,
        public string $credit,
        public string $description,
    ) {}
}
