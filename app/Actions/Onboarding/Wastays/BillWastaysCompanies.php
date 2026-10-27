<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\CityLedger\AddOldSystemCredit;
use App\Actions\CityLedger\AllocateArPayment;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\CityLedger\ArPaymentBalance;
use App\Services\CityLedger\CreditExposure;
use App\Services\Folio\FolioBalance;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Brings the company billing of a replayed wastays hotel into the city ledger. A replay leaves the stays wastays billed to
 * companies on open company folios, so importing the invoices as well would count the debt twice. Each wastays invoice
 * settles the company folio that holds exactly its amount, keeping its wastays number and dates. The folios no invoice
 * matches settle too, dated by the stay. Each wastays company payment comes in with the allocations wastays made, and each
 * company keeps only the credit wastays shows: the rest goes to its oldest open invoices. A dry run, or any problem,
 * rolls everything back. See docs/migration-plan/sand-bay-resort-semporna.md "Order".
 */
class BillWastaysCompanies
{
    public function __construct(
        private SettleToCityLedger $settle,
        private AddOldSystemCredit $addCredit,
        private AllocateArPayment $allocate,
        private FolioBalance $folioBalance,
        private ArPaymentBalance $paymentBalance,
        private ArInvoiceBalance $invoiceBalance,
        private CreditExposure $creditExposure,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
    ) {}

    /**
     * A dry run does everything and rolls back.
     */
    public function __invoke(WastaysImportTarget $target, bool $dryRun = false): WastaysReplaySummary
    {
        $summary = new WastaysReplaySummary;

        DB::beginTransaction();

        try {
            $made = $this->invoices($target, $summary);
            $payments = $this->payments($target, $summary, $made);
            $this->surplusCredit($target, $summary, $payments);
            $this->reconcile($target, $summary);

            $problems = $summary->counts['problems'] ?? 0;
            $dryRun || $problems > 0 ? DB::rollBack() : DB::commit();

            if ($problems > 0 && ! $dryRun) {
                $summary->note('Problems found, so nothing was written.');
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $summary;
    }

    /**
     * @return array<string, int> AR invoice id by wastays invoice id
     */
    private function invoices(WastaysImportTarget $target, WastaysReplaySummary $summary): array
    {
        $property = $target->property;
        $folioBooking = $target->bundle->rows('booking_folios')->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['booking_id']])->all();
        $made = [];
        $used = [];

        foreach ($target->bundle->rows('ar_invoices')->sortBy(fn (array $row): int => (int) $row['id']) as $invoice) {
            $id = (string) $invoice['id'];
            $reference = filled($invoice['invoice_reference'] ?? null) ? (string) $invoice['invoice_reference'] : $invoice['invoice_year'].'-'.$invoice['invoice_number'];

            if ($invoice['status'] === 'void') {
                $summary->count('void invoices left in wastays');

                continue;
            }

            $existing = $this->links->targetId($property, 'ar_invoices', $id);

            if ($existing !== null) {
                $made[$id] = $existing;
                $summary->count('already imported');

                continue;
            }

            $company = $this->links->target($property, 'hotel_corporate_accounts', (string) $invoice['hotel_corporate_account_id']);

            if (! $company instanceof Company) {
                $this->problem($summary, "invoice {$reference}: import the companies first.");

                continue;
            }

            $amount = BigDecimal::of((string) $invoice['amount']);
            $first = $this->links->target($property, 'bookings', $folioBooking[(string) $invoice['booking_folio_id']] ?? '');
            $firstFolio = $first instanceof ReservationUnit ? Folio::query()->where('reservation_id', $first->reservation_id)->where('type', FolioType::Master)->orderBy('id')->first() : null;
            $pool = $this->openFinishedFolios($property->id, $company->id)->reject(fn (Folio $folio): bool => isset($used[$folio->id]));
            $match = $firstFolio !== null && $pool->contains('id', $firstFolio->id) && BigDecimal::of($this->folioBalance->forFolio($firstFolio))->isEqualTo($amount)
                ? $firstFolio
                : $pool->first(fn (Folio $folio): bool => BigDecimal::of($this->folioBalance->forFolio($folio))->isEqualTo($amount));

            if (! $match instanceof Folio) {
                $summary->count('wastays invoices with no company folio of that amount');
                $summary->note("wastays invoice {$reference} (company {$company->id}, RM {$amount->toScale(2)}) has no company folio holding exactly that, so it is not brought in");

                continue;
            }

            $used[$match->id] = true;
            $result = ($this->settle)($target->actor, $match);

            if ($result->refusal !== null || $result->invoice === null) {
                $this->problem($summary, "invoice {$reference}: the folio could not be settled ({$result->refusal}).");

                continue;
            }

            $due = max((string) $invoice['due_on'], (string) $invoice['issued_on']);
            // The wastays number and dates. ar_invoices is not an immutable ledger, the folio lines are.
            DB::table('ar_invoices')->where('id', $result->invoice->id)->update([
                'number' => $reference,
                'issued_on' => $invoice['issued_on'],
                'due_on' => $due,
                'migrated' => true,
                'reason' => 'Migrated from wastays',
            ]);
            ($this->recordImportLink)($property, 'ar_invoices', $id, $result->invoice);
            $made[$id] = $result->invoice->id;
            $summary->count('invoices with their wastays number');
        }

        $this->unmatchedFolios($target, $summary, $used);

        return $made;
    }

    /**
     * Folios of the same companies that no wastays invoice matches still hold real charges, so they settle too.
     *
     * @param  array<int, bool>  $used
     */
    private function unmatchedFolios(WastaysImportTarget $target, WastaysReplaySummary $summary, array $used): void
    {
        $property = $target->property;
        $companyIds = $target->bundle->rows('ar_invoices')->pluck('hotel_corporate_account_id')->unique()
            ->map(fn ($account): ?int => $this->links->target($property, 'hotel_corporate_accounts', (string) $account)?->getKey())
            ->filter()->values();

        foreach ($companyIds as $companyId) {
            foreach ($this->openFinishedFolios($property->id, (int) $companyId)->reject(fn (Folio $folio): bool => isset($used[$folio->id])) as $folio) {
                if (! BigDecimal::of($this->folioBalance->forFolio($folio))->isPositive()) {
                    continue;
                }

                $result = ($this->settle)($target->actor, $folio);

                if ($result->refusal !== null || $result->invoice === null) {
                    $this->problem($summary, "folio {$folio->id}: it could not be settled ({$result->refusal}).");

                    continue;
                }

                $summary->count('estatell invoices with no wastays invoice');
            }
        }
    }

    /**
     * @param  array<string, int>  $made
     * @return array<string, ArPayment> by wastays payment id
     */
    private function payments(WastaysImportTarget $target, WastaysReplaySummary $summary, array $made): array
    {
        $property = $target->property;
        $voided = $target->bundle->rows('ar_payment_voids')->pluck('ar_payment_id')->map(fn ($id): string => (string) $id)->flip()->all();
        $reversed = $target->bundle->rows('ar_payment_allocation_reversals')->pluck('ar_payment_allocation_id')->map(fn ($id): string => (string) $id)->flip()->all();
        $payments = [];

        foreach ($target->bundle->rows('ar_payments')->sortBy(fn (array $row): int => (int) $row['id']) as $row) {
            if (isset($voided[(string) $row['id']])) {
                continue;
            }

            $company = $this->links->target($property, 'hotel_corporate_accounts', (string) $row['hotel_corporate_account_id']);

            if (! $company instanceof Company) {
                $this->problem($summary, "payment {$row['reference_number']}: import the companies first.");

                continue;
            }

            $result = ($this->addCredit)(
                $target->actor,
                $company,
                (string) $row['reference_number'],
                CarbonImmutable::parse((string) $row['received_at']),
                (string) BigDecimal::of((string) $row['amount'])->toScale(2),
                'Migrated from wastays',
                'wastays:bill-companies:ar_payments:'.$row['id'],
                importing: true,
            );

            if ($result->isRefused() || $result->payment === null) {
                $this->problem($summary, "payment {$row['reference_number']}: refused ({$result->refusal}).");

                continue;
            }

            $payments[(string) $row['id']] = $result->payment;
            $summary->count($result->replayed ? 'payments already imported' : 'company payments');
        }

        foreach ($target->bundle->rows('ar_payment_allocations')->sortBy(fn (array $row): int => (int) $row['id']) as $row) {
            $allocationId = (string) $row['id'];
            $payment = $payments[(string) $row['ar_payment_id']] ?? null;
            $invoiceId = $made[(string) $row['ar_invoice_id']] ?? null;

            if (isset($reversed[$allocationId]) || $this->links->has($property, 'ar_payment_allocations', $allocationId)) {
                continue;
            }

            if ($payment === null || $invoiceId === null) {
                $summary->count('allocations to an invoice with no company folio of that amount');

                continue;
            }

            $result = ($this->allocate)($target->actor, $payment->fresh() ?? $payment, [$invoiceId => (string) BigDecimal::of((string) $row['amount'])->toScale(2)]);

            if ($result->isRefused()) {
                $this->problem($summary, "allocation {$allocationId}: refused ({$result->refusal}).");

                continue;
            }

            ($this->recordImportLink)($property, 'ar_payment_allocations', $allocationId, $payment);
            $summary->count('payment allocations');
        }

        return $payments;
    }

    /**
     * wastays settled some invoices that estatell holds as several folios, so the payments for them are credit until they
     * are applied. Each company keeps only the credit wastays shows, and the rest goes to its oldest open invoices.
     *
     * @param  array<string, ArPayment>  $payments
     */
    private function surplusCredit(WastaysImportTarget $target, WastaysReplaySummary $summary, array $payments): void
    {
        $property = $target->property;
        $reversed = $target->bundle->rows('ar_payment_allocation_reversals')->pluck('ar_payment_allocation_id')->map(fn ($id): string => (string) $id)->flip()->all();
        $keep = [];

        foreach ($target->bundle->rows('ar_payments') as $row) {
            $payment = $payments[(string) $row['id']] ?? null;

            if ($payment !== null) {
                $keep[$payment->company_id] = ($keep[$payment->company_id] ?? BigDecimal::zero())->plus((string) $row['amount']);
            }
        }

        foreach ($target->bundle->rows('ar_payment_allocations') as $row) {
            $payment = $payments[(string) $row['ar_payment_id']] ?? null;

            if ($payment !== null && ! isset($reversed[(string) $row['id']])) {
                $keep[$payment->company_id] = $keep[$payment->company_id]->minus((string) $row['amount']);
            }
        }

        foreach ($keep as $companyId => $credit) {
            $invoices = ArInvoice::query()->where('property_id', $property->id)->where('company_id', $companyId)->where('type', ArInvoiceType::Invoice)
                ->where('status', '<>', ArInvoiceStatus::Void)->orderBy('issued_on')->orderBy('id')->get();

            foreach (ArPayment::query()->where('property_id', $property->id)->where('company_id', $companyId)->orderBy('received_on')->orderBy('id')->get() as $payment) {
                $free = BigDecimal::of($this->paymentBalance->unallocated($payment));
                $surplus = $this->unallocatedOf($property->id, $companyId)->minus($credit);

                if (! $surplus->isPositive() || ! $free->isPositive()) {
                    continue;
                }

                $give = $free->isLessThan($surplus) ? $free : $surplus;

                foreach ($invoices as $invoice) {
                    $owed = BigDecimal::of($this->invoiceBalance->forInvoice($invoice));

                    if (! $give->isPositive()) {
                        break;
                    }

                    if (! $owed->isPositive()) {
                        continue;
                    }

                    $part = $owed->isLessThan($give) ? $owed : $give;
                    $result = ($this->allocate)($target->actor, $payment->fresh() ?? $payment, [$invoice->id => (string) $part->toScale(2)]);

                    if ($result->isRefused()) {
                        $this->problem($summary, "credit of company {$companyId}: refused ({$result->refusal}).");

                        break 2;
                    }

                    $give = $give->minus($part);
                    $summary->count('credit applied to an oldest invoice');
                }
            }
        }
    }

    /**
     * What each company owes in estatell against what wastays shows, one note per company.
     */
    private function reconcile(WastaysImportTarget $target, WastaysReplaySummary $summary): void
    {
        $property = $target->property;
        $reversed = $target->bundle->rows('ar_payment_allocation_reversals')->pluck('ar_payment_allocation_id')->map(fn ($id): string => (string) $id)->flip()->all();
        $paidTo = [];

        foreach ($target->bundle->rows('ar_payment_allocations') as $row) {
            if (! isset($reversed[(string) $row['id']])) {
                $paidTo[(string) $row['ar_invoice_id']] = ($paidTo[(string) $row['ar_invoice_id']] ?? BigDecimal::zero())->plus((string) $row['amount']);
            }
        }

        $owes = [];

        foreach ($target->bundle->rows('ar_invoices') as $row) {
            if ($row['status'] !== 'void') {
                $account = (string) $row['hotel_corporate_account_id'];
                $owes[$account] = ($owes[$account] ?? BigDecimal::zero())->plus(BigDecimal::of((string) $row['amount'])->minus($paidTo[(string) $row['id']] ?? '0'));
            }
        }

        $companies = Company::query()->where('property_id', $property->id)->get();
        $exposure = $this->creditExposure->forCompanies($companies);

        foreach ($owes as $account => $wastays) {
            $company = $this->links->target($property, 'hotel_corporate_accounts', (string) $account);
            $figures = $company instanceof Company ? ($exposure[$company->id] ?? null) : null;

            if ($company === null || $figures === null) {
                continue;
            }

            $difference = BigDecimal::of($figures['exposure'])->minus($wastays);
            $summary->count($difference->abs()->isGreaterThan('0.5') ? 'companies that differ from wastays' : 'companies that match wastays');
            $summary->note("company {$company->id}: estatell owes {$figures['exposure']} (invoices {$figures['openAr']} and open folios {$figures['inHouse']}), wastays owes {$wastays->toScale(2)}, difference {$difference->toScale(2)}");
        }

        $summary->note('credit on account: '.$this->unallocatedOf($property->id, null)->toScale(2));
    }

    /**
     * Open company folios of stays that are all finished.
     *
     * @return Collection<int, Folio>
     */
    private function openFinishedFolios(int $propertyId, int $companyId): Collection
    {
        return Folio::query()
            ->where('property_id', $propertyId)
            ->where('type', FolioType::Master)
            ->where('status', FolioStatus::Open)
            ->where('payer_company_id', $companyId)
            ->orderBy('id')
            ->get()
            ->filter(fn (Folio $folio): bool => ReservationUnit::query()->where('reservation_id', $folio->reservation_id)->get()
                ->every(fn (ReservationUnit $unit): bool => in_array($unit->status->value, ['checked_out', 'cancelled'], true)))
            ->values();
    }

    private function unallocatedOf(int $propertyId, ?int $companyId): BigDecimal
    {
        return ArPayment::query()->where('property_id', $propertyId)->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))->get()
            ->reduce(fn (BigDecimal $total, ArPayment $payment): BigDecimal => $total->plus($this->paymentBalance->unallocated($payment)), BigDecimal::zero());
    }

    private function problem(WastaysReplaySummary $summary, string $note): void
    {
        $summary->count('problems');
        $summary->note($note);
    }
}
