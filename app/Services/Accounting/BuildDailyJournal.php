<?php

namespace App\Services\Accounting;

use App\Enums\SystemTransactionCode;
use App\Models\AccountingConnection;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The balanced journal of one business date, read from the folio ledger by posting date. Each transaction code with a
 * net amount becomes one line: a positive net (charges, taxes) is a credit, a negative net (payments, discounts) is a
 * debit. The difference goes to the guest ledger account, so the journal always balances. Lines copied from the old
 * system and the Opening balance code are left out, as in the daily revenue report. It reads only.
 * See docs/26-accounting/01-daily-journal.md.
 */
class BuildDailyJournal
{
    /**
     * Null when the date has no money lines. Throws when a code with an amount has no account or the guest ledger account
     * is not set.
     *
     * @throws AccountingException
     */
    public function forDate(Property $property, AccountingConnection $connection, BusinessDate $date): ?AccountingJournal
    {
        $rows = $this->totals($property, $date);

        if ($rows === []) {
            return null;
        }

        $unmapped = array_values(array_map(
            fn (array $row): string => $row['code'],
            array_filter($rows, fn (array $row): bool => $row['account'] === null),
        ));

        if ($unmapped !== []) {
            throw AccountingException::unmappedCodes($unmapped);
        }

        $guestLedger = $connection->guest_ledger_account_code;

        if (blank($guestLedger)) {
            throw AccountingException::guestLedgerMissing();
        }

        $label = $date->date->toDateString();
        $lines = [];
        $balance = BigDecimal::zero();

        foreach ($rows as $row) {
            $net = $row['net'];
            $balance = $balance->plus($net);
            $lines[] = new AccountingJournalLine(
                accountCode: (string) $row['account'],
                debit: $net->isNegative() ? (string) $net->abs()->toScale(2) : '0.00',
                credit: $net->isPositive() ? (string) $net->toScale(2) : '0.00',
                description: "{$row['name']} {$label}",
            );
        }

        if (! $balance->isZero()) {
            $lines[] = new AccountingJournalLine(
                accountCode: (string) $guestLedger,
                debit: $balance->isPositive() ? (string) $balance->toScale(2) : '0.00',
                credit: $balance->isNegative() ? (string) $balance->abs()->toScale(2) : '0.00',
                description: __('Guest ledger :date', ['date' => $label]),
            );
        }

        return new AccountingJournal(
            date: CarbonImmutable::parse($label),
            currency: $property->loadMissing('settings')->settings->currency->getCurrencyCode(),
            reference: "EST-{$property->code}-{$label}",
            description: "{$property->name} daily journal {$label}",
            lines: $lines,
        );
    }

    /**
     * The net amount of each transaction code posted on the date, skipping codes that net to zero.
     *
     * @return list<array{code: string, name: string, account: string|null, net: BigDecimal}>
     */
    private function totals(Property $property, BusinessDate $date): array
    {
        $rows = FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->join('transaction_codes', 'transaction_codes.id', '=', 'folio_lines.transaction_code_id')
            ->where('folios.property_id', $property->id)
            ->where('folio_lines.migrated', false)
            ->where('folio_lines.posting_date', $date->date->toDateString())
            ->where(fn (Builder $codes) => $codes
                ->whereNull('transaction_codes.system_key')
                ->orWhere('transaction_codes.system_key', '!=', SystemTransactionCode::OpeningBalance->value))
            ->groupBy('transaction_codes.id', 'transaction_codes.code', 'transaction_codes.name', 'transaction_codes.gl_account_code')
            ->orderBy('transaction_codes.code')
            ->selectRaw('transaction_codes.code as code, transaction_codes.name as name, transaction_codes.gl_account_code as account, SUM(folio_lines.amount) as net')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $net = BigDecimal::of((string) $row->getAttribute('net'))->toScale(2, RoundingMode::HalfUp);

            if ($net->isZero()) {
                continue;
            }

            $account = $row->getAttribute('account');

            $totals[] = [
                'code' => (string) $row->getAttribute('code'),
                'name' => (string) $row->getAttribute('name'),
                'account' => is_string($account) && trim($account) !== '' ? trim($account) : null,
                'net' => $net,
            ];
        }

        return $totals;
    }
}
