<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\CityLedger\AddOldSystemCredit;
use App\Actions\CityLedger\AddOldSystemInvoice;
use App\Models\Company;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Brings the money companies owe and have paid in advance across from wastays on go-live day: what is still unpaid on
 * each invoice as an Old system invoice, and what is left of each payment as Old system credit. The amounts are worked
 * out from the allocations that were never reversed, never from the stored `paid_amount`. Void invoices and voided
 * payments are left. Each row has an `import_links` row, so a second run writes nothing. See
 * docs/migration-plan/sand-bay-resort-semporna.md "Open at cutover".
 */
class ImportWastaysBalances
{
    public function __construct(
        private AddOldSystemInvoice $addInvoice,
        private AddOldSystemCredit $addCredit,
        private RecordImportLink $recordImportLink,
        private FirstBusinessDate $firstBusinessDate,
        private ImportLinks $links,
    ) {}

    /**
     * A dry run does everything and rolls back.
     *
     * @throws InvalidArgumentException when the property has no first business date
     */
    public function __invoke(WastaysImportTarget $target, bool $dryRun = false): WastaysReplaySummary
    {
        $property = $target->property;
        $goLive = $this->firstBusinessDate->date($property) ?? throw new InvalidArgumentException('The property has no business date. Take it live first.');
        $summary = new WastaysReplaySummary;
        $bundle = $target->bundle;

        $reversed = $bundle->rows('ar_payment_allocation_reversals')->pluck('ar_payment_allocation_id')->map(fn ($id): string => (string) $id)->flip()->all();
        $allocatedToInvoice = [];
        $allocatedFromPayment = [];

        foreach ($bundle->rows('ar_payment_allocations') as $allocation) {
            if (isset($reversed[(string) $allocation['id']])) {
                continue;
            }

            $amount = BigDecimal::of($allocation['amount']);
            $invoiceId = (string) $allocation['ar_invoice_id'];
            $paymentId = (string) $allocation['ar_payment_id'];
            $allocatedToInvoice[$invoiceId] = ($allocatedToInvoice[$invoiceId] ?? BigDecimal::zero())->plus($amount);
            $allocatedFromPayment[$paymentId] = ($allocatedFromPayment[$paymentId] ?? BigDecimal::zero())->plus($amount);
        }

        $voided = $bundle->rows('ar_payment_voids')->pluck('ar_payment_id')->map(fn ($id): string => (string) $id)->flip()->all();

        DB::beginTransaction();

        try {
            foreach ($bundle->rows('ar_invoices')->sortBy(fn (array $row): int => (int) $row['id']) as $invoice) {
                $this->invoice($target, $summary, $goLive, $invoice, $allocatedToInvoice[(string) $invoice['id']] ?? BigDecimal::zero());
            }

            foreach ($bundle->rows('ar_payments')->sortBy(fn (array $row): int => (int) $row['id']) as $payment) {
                $this->payment($target, $summary, $payment, isset($voided[(string) $payment['id']]), $allocatedFromPayment[(string) $payment['id']] ?? BigDecimal::zero());
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $summary;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function invoice(WastaysImportTarget $target, WastaysReplaySummary $summary, CarbonImmutable $goLive, array $row, BigDecimal $allocated): void
    {
        $id = (string) $row['id'];
        $property = $target->property;

        if ($this->links->has($property, 'ar_invoices', $id)) {
            $summary->count('already imported');

            return;
        }

        if ($row['status'] === 'void') {
            $summary->count('void invoices left in wastays');

            return;
        }

        $owed = BigDecimal::of($row['amount'])->minus($allocated);

        if (! $owed->isGreaterThan(0)) {
            $summary->count('invoices fully paid, left in wastays');

            return;
        }

        $number = $this->reference($row);
        $company = $this->company($target, (string) $row['hotel_corporate_account_id']);

        if ($company === null) {
            $this->stopped($summary, "invoice {$number}: import the companies first.");

            return;
        }

        $issuedOn = CarbonImmutable::parse((string) $row['issued_on']);
        $dueOn = CarbonImmutable::parse((string) $row['due_on']);

        if ($dueOn->lessThan($issuedOn)) {
            $dueOn = $issuedOn;
            $summary->count('due dates before the issue date moved to the issue date');
        }

        $result = ($this->addInvoice)($target->actor, $company, $number, $issuedOn, $dueOn, (string) $owed->toScale(2), 'Migrated from wastays. Original total '.BigDecimal::of($row['amount'])->toScale(2), importing: true);

        if ($result->refusal !== null || $result->invoice === null) {
            $this->stopped($summary, "invoice {$number}: refused ({$result->refusal}).");

            return;
        }

        ($this->recordImportLink)($property, 'ar_invoices', $id, $result->invoice);
        $summary->count('unpaid invoices');
        $summary->note('invoice '.$number.' owed '.$owed->toScale(2));
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function payment(WastaysImportTarget $target, WastaysReplaySummary $summary, array $row, bool $voided, BigDecimal $allocated): void
    {
        $id = (string) $row['id'];
        $property = $target->property;

        if ($this->links->has($property, 'ar_payments', $id)) {
            $summary->count('already imported');

            return;
        }

        if ($voided) {
            $summary->count('voided payments left in wastays');

            return;
        }

        $credit = BigDecimal::of($row['amount'])->minus($allocated);

        if (! $credit->isGreaterThan(0)) {
            $summary->count('payments fully used, left in wastays');

            return;
        }

        $reference = (string) $row['reference_number'];
        $company = $this->company($target, (string) $row['hotel_corporate_account_id']);

        if ($company === null) {
            $this->stopped($summary, "payment {$reference}: import the companies first.");

            return;
        }

        $result = ($this->addCredit)(
            $target->actor,
            $company,
            $reference,
            CarbonImmutable::parse((string) $row['received_at']),
            (string) $credit->toScale(2),
            'Migrated from wastays. Original payment '.BigDecimal::of($row['amount'])->toScale(2),
            'wastays:import-balances:ar_payments:'.$id,
            importing: true,
        );

        if ($result->isRefused() || $result->payment === null) {
            $this->stopped($summary, "payment {$reference}: refused ({$result->refusal}).");

            return;
        }

        ($this->recordImportLink)($property, 'ar_payments', $id, $result->payment);
        $summary->count('credit on account');
        $summary->note('payment '.$reference.' credit '.$credit->toScale(2));
    }

    /**
     * The number wastays printed on the invoice, such as SBR-264000xx.
     *
     * @param  array<string, string|null>  $row
     */
    private function reference(array $row): string
    {
        return filled($row['invoice_reference'] ?? null) ? (string) $row['invoice_reference'] : $row['invoice_year'].'-'.$row['invoice_number'];
    }

    private function company(WastaysImportTarget $target, string $wastaysId): ?Company
    {
        $company = $this->links->target($target->property, 'hotel_corporate_accounts', $wastaysId);

        return $company instanceof Company ? $company : null;
    }

    private function stopped(WastaysReplaySummary $summary, string $note): void
    {
        $summary->count('rows that could not be imported');
        $summary->note($note);
    }
}
