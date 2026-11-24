<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\PosOrder;
use App\Models\Property;
use App\Models\User;
use App\Services\EInvoice\ConsolidatedBatchBuilder;
use App\Services\EInvoice\FolioEInvoiceLock;
use App\Services\EInvoice\PosOrderReportLines;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ConsolidateMonthlyEInvoices
{
    public function __construct(
        private SendEInvoiceToLhdn $sendEInvoiceToLhdn,
        private LockEInvoiceRecords $lockEInvoiceRecords,
        private ConsolidatedBatchBuilder $consolidatedBatchBuilder,
        private FolioEInvoiceLock $folioEInvoiceLock,
        private PosOrderReportLines $posOrderReportLines,
    ) {}

    /**
     * Send the month's closed stays that have no e-invoice of their own as monthly summaries of at most 100 stays.
     * Choosing the stays, building every part and saving their Pending records run in one transaction under the
     * property's e-invoice lock (`LockEInvoiceRecords`): if any part cannot be built, nothing is saved and the error
     * reaches the caller (`MissingEInvoiceDetails` for a gap in the hotel's details), so no stay is left out quietly.
     * Each part is then sent on its own (`SendEInvoiceToLhdn`).
     *
     * @return list<EInvoiceSubmission>
     */
    public function __invoke(Property $property, CarbonImmutable $month, ?User $actor = null): array
    {
        $setting = $property->eInvoiceSetting;
        if ($setting === null || ! $setting->enabled || ! $setting->consolidation_enabled) {
            return [];
        }

        $prepared = ($this->lockEInvoiceRecords)($property, function () use ($property, $month): array {
            $parts = [];

            // Stays first, then walk-in sales: one document holds at most 100 lines of both together.
            $items = $this->eligibleFolios($property, $month)->toBase()->merge($this->eligiblePosOrders($property, $month));

            foreach ($items->chunk(ConsolidatedBatchBuilder::MAX_FOLIOS_PER_DOCUMENT) as $chunk) {
                $folios = new EloquentCollection($chunk->whereInstanceOf(Folio::class)->values()->all());
                $posOrders = new EloquentCollection($chunk->whereInstanceOf(PosOrder::class)->values()->all());
                $built = $this->consolidatedBatchBuilder->build($property, $folios, $month->startOfMonth(), $posOrders);

                $submission = EInvoiceSubmission::query()->create([
                    'property_id' => $property->id,
                    'document_type' => EInvoiceDocumentType::Invoice,
                    'scenario' => EInvoiceScenario::Consolidation,
                    'status' => EInvoiceStatus::Pending,
                    'internal_id' => $built['codeNumber'],
                    'buyer_snapshot' => ConsolidatedBatchBuilder::generalPublicBuyer(),
                    'document_payload' => $built['payload'],
                    'is_consolidated' => true,
                    'requested_by_guest' => false,
                ]);
                // Each stay with the document line that holds it, so the summary page reads its amounts from that line.
                $submission->folios()->attach(collect($built['lines'])->map(fn (int $line): array => ['line_number' => $line])->all());
                $submission->posOrders()->attach(collect($built['posLines'])->map(fn (int $line): array => ['line_number' => $line, 'is_active' => true])->all());
                PosOrder::query()->whereIn('id', array_keys($built['posLines']))->update(['e_invoice_submission_id' => $submission->id]);

                $parts[] = [$submission, $built];
            }

            return $parts;
        });

        $submissions = [];

        foreach ($prepared as [$submission, $built]) {
            $submission = ($this->sendEInvoiceToLhdn)($submission, $built);

            if ($actor !== null) {
                activity()
                    ->performedOn($submission)
                    ->causedBy($actor)
                    ->event('einvoice.consolidated')
                    ->withProperties([
                        'submission_id' => $submission->id,
                        'internal_id' => $submission->internal_id,
                        'uuid' => $submission->uuid,
                    ])
                    ->log('Submitted monthly consolidated e-invoice');
            }

            $submissions[] = $submission;
        }

        return $submissions;
    }

    /**
     * The month's closed stays over RM0 and at most RM10,000 that are not on an e-invoice of their own, not in a
     * monthly summary LHDN has or is checking, and not billed to a company (a City ledger invoice e-invoices those).
     *
     * @return Collection<int, Folio>
     */
    private function eligibleFolios(Property $property, CarbonImmutable $month): Collection
    {
        $candidateFolios = $this->folioEInvoiceLock->whereStillToReport(Folio::query(), $property->id)
            ->where('property_id', $property->id)
            ->where('status', FolioStatus::Closed)
            ->whereBetween('closed_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->with('lines.transactionCode')
            ->get();

        // A stay over RM10,000 needs its own e-invoice: LHDN does not take it in a summary.
        $eligibleFolios = $candidateFolios->filter(fn (Folio $folio): bool => $this->consolidatedBatchBuilder->fitsInSummary($folio))->values();

        return $eligibleFolios;
    }

    /**
     * The month's settled walk-in sales (the part not charged to a room) of at most RM10,000 that no e-invoice holds, and
     * the month's refunds of those sales. A sale and its refunds that net to RM0 are left out. A refund of a sale in
     * another document is not here: it needs a credit note.
     *
     * @return Collection<int, PosOrder>
     */
    private function eligiblePosOrders(Property $property, CarbonImmutable $month): Collection
    {
        $candidates = PosOrder::query()
            ->where('property_id', $property->id)
            ->where('status', PosOrderStatus::Settled)
            ->whereNull('e_invoice_submission_id')
            ->whereBetween('business_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->whereRaw('grand_total - folio_posted_amount > 0')
            ->with('lines.taxes')
            ->orderBy('id')
            ->get();

        $sales = $candidates->where('order_type', PosOrderType::Sale)->filter(fn (PosOrder $order): bool => ! $this->posOrderReportLines->isOverLimit($order))->keyBy('id');
        $refunds = $candidates->where('order_type', PosOrderType::Refund)->filter(fn (PosOrder $order): bool => $sales->has($order->refund_for_order_id));

        return $sales
            ->filter(fn (PosOrder $sale): bool => ! $this->groupGross($refunds->where('refund_for_order_id', $sale->id)->prepend($sale))->isZero())
            ->flatMap(fn (PosOrder $sale): Collection => $refunds->where('refund_for_order_id', $sale->id)->prepend($sale))
            ->values();
    }

    /**
     * What a sale and its refunds add up to, tax included.
     *
     * @param  Collection<int, PosOrder>  $orders
     */
    private function groupGross(Collection $orders): BigDecimal
    {
        return $orders->reduce(function (BigDecimal $sum, PosOrder $order): BigDecimal {
            $split = $this->posOrderReportLines->split($order);

            return $sum->plus($split['net'])->plus($split['tax']);
        }, BigDecimal::zero());
    }
}
