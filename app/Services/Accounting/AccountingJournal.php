<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;

/**
 * One balanced journal for a business date, in the property's currency. The lines carry the account codes of the
 * accounting software, not its internal ids.
 */
final readonly class AccountingJournal
{
    /**
     * @param  list<AccountingJournalLine>  $lines
     */
    public function __construct(
        public CarbonImmutable $date,
        public string $currency,
        public string $reference,
        public string $description,
        public array $lines,
    ) {}

    /**
     * @return list<string>
     */
    public function accountCodes(): array
    {
        return array_values(array_unique(array_map(fn (AccountingJournalLine $line): string => $line->accountCode, $this->lines)));
    }
}
