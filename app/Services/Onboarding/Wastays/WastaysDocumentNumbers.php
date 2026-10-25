<?php

namespace App\Services\Onboarding\Wastays;

/**
 * The document numbers wastays issued, found from the row they belong to: the receipt of a payment line or a deposit,
 * and the invoice of a booking's folio. The replay pushes them onto `PlannedDocumentNumbers`. It reads only.
 * See docs/migration-plan/grand-eastern-hotel.md "Document numbers".
 */
final class WastaysDocumentNumbers
{
    /** @var array<string, int>|null */
    private ?array $receipts = null;

    /** @var array<string, int>|null */
    private ?array $invoices = null;

    /** @var array<string, string>|null */
    private ?array $receiptReferences = null;

    public function __construct(private readonly WastaysBundle $bundle) {}

    /**
     * @param  string  $kind  `folio_transaction` or `deposit`
     */
    public function receipt(string $kind, string $sourceId): ?int
    {
        if ($this->receipts === null) {
            $this->receipts = [];

            foreach ($this->rows('receipts') as $row) {
                foreach (['folio_transaction' => 'folio_transaction_id', 'deposit' => 'deposit_id'] as $name => $column) {
                    if ($row[$column] !== null) {
                        $this->receipts[$name.':'.$row[$column]] = (int) $row['receipt_number'];
                    }
                }
            }
        }

        return $this->receipts[$kind.':'.$sourceId] ?? null;
    }

    /**
     * The wastays receipt as a payment reference, such as "wastays GEH-26500012", for a payment wastays saved without one.
     *
     * @param  string  $kind  `folio_transaction` or `deposit`
     */
    public function receiptReference(string $kind, string $sourceId): ?string
    {
        if ($this->receiptReferences === null) {
            $this->receiptReferences = [];

            foreach ($this->rows('receipts') as $row) {
                foreach (['folio_transaction' => 'folio_transaction_id', 'deposit' => 'deposit_id'] as $name => $column) {
                    if ($row[$column] !== null && $row['public_number'] !== null) {
                        $this->receiptReferences[$name.':'.$row[$column]] = 'wastays '.$row['public_number'];
                    }
                }
            }
        }

        return $this->receiptReferences[$kind.':'.$sourceId] ?? null;
    }

    /**
     * The highest receipt number wastays issued, for any receipt (payment, deposit or company payment).
     */
    public function highestReceipt(): ?int
    {
        $highest = null;

        foreach ($this->rows('receipts') as $row) {
            $highest = max($highest ?? 0, (int) $row['receipt_number']);
        }

        return $highest;
    }

    public function invoice(string $bookingId): ?int
    {
        if ($this->invoices === null) {
            $this->invoices = [];
            $folios = [];

            foreach ($this->rows('booking_folios') as $row) {
                $folios[$row['id']] = $row['booking_id'];
            }

            foreach ($this->rows('invoices') as $row) {
                $this->invoices[$folios[$row['booking_folio_id']] ?? '-'] = (int) $row['invoice_number'];
            }
        }

        return $this->invoices[$bookingId] ?? null;
    }

    /**
     * @return iterable<array<string, string|null>>
     */
    private function rows(string $table): iterable
    {
        return array_key_exists($table.'.csv', $this->bundle->manifest->files) ? $this->bundle->rows($table) : [];
    }
}
