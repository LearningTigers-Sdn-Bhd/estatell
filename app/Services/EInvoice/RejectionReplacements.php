<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What took each rejected document's place, for many documents in a fixed number of queries, so the e-invoice list
 * and its rejected count do not run queries per row. The rules, in this order:
 *
 * - A credit or refund note: a later note on the same e-invoice that LHDN has or is checking.
 * - A monthly summary: a later summary that LHDN has or is checking and holds one of its stays.
 * - A City ledger e-invoice: the invoice's e-invoice that LHDN has or is checking.
 * - A stay e-invoice: the stay's own e-invoice or a monthly summary it is in, as `FolioEInvoiceLock` reads it.
 *
 * A document that is not rejected has no replacement. Only the newest replacement counts. It reads only.
 */
class RejectionReplacements
{
    /**
     * @param  iterable<EInvoiceSubmission>  $submissions
     * @return array<int, EInvoiceSubmission> The replacement by rejected document id; documents with none are left out.
     */
    public function for(iterable $submissions): array
    {
        $rejected = collect($submissions)->filter(fn (EInvoiceSubmission $submission): bool => $submission->status === EInvoiceStatus::Invalid)->values();
        if ($rejected->isEmpty()) {
            return [];
        }

        $notes = $rejected->filter(fn (EInvoiceSubmission $submission): bool => $submission->original_submission_id !== null);
        $summaries = $rejected->filter(fn (EInvoiceSubmission $submission): bool => $submission->original_submission_id === null && $submission->is_consolidated);
        $arInvoices = $rejected->filter(fn (EInvoiceSubmission $submission): bool => $submission->original_submission_id === null && ! $submission->is_consolidated && $submission->ar_invoice_id !== null);
        $stays = $rejected->filter(fn (EInvoiceSubmission $submission): bool => $submission->original_submission_id === null && ! $submission->is_consolidated && $submission->ar_invoice_id === null && $submission->folio_id !== null);

        return $this->notes($notes) + $this->summaries($summaries) + $this->arInvoices($arInvoices) + $this->stays($stays);
    }

    /**
     * @param  Collection<int, EInvoiceSubmission>  $notes
     * @return array<int, EInvoiceSubmission>
     */
    private function notes(Collection $notes): array
    {
        if ($notes->isEmpty()) {
            return [];
        }

        $candidates = EInvoiceSubmission::query()->whereIn('original_submission_id', $notes->pluck('original_submission_id')->unique())->active()->orderByDesc('id')->get();

        return $notes->mapWithKeys(fn (EInvoiceSubmission $note): array => [$note->id => $candidates->first(
            fn (EInvoiceSubmission $candidate): bool => $candidate->original_submission_id === $note->original_submission_id && $candidate->id > $note->id,
        )])->filter()->all();
    }

    /**
     * @param  Collection<int, EInvoiceSubmission>  $summaries
     * @return array<int, EInvoiceSubmission>
     */
    private function summaries(Collection $summaries): array
    {
        if ($summaries->isEmpty()) {
            return [];
        }

        $stayIds = $this->folioIdsBySubmission($summaries->pluck('id'));
        $allStayIds = collect($stayIds)->flatten()->unique()->values();
        if ($allStayIds->isEmpty()) {
            return [];
        }

        $candidates = EInvoiceSubmission::query()
            ->whereIn('property_id', $summaries->pluck('property_id')->unique())
            ->where('is_consolidated', true)
            ->active()
            ->whereHas('folios', fn ($folios) => $folios->whereIn('folios.id', $allStayIds))
            ->orderByDesc('id')
            ->get();
        $candidateStays = $this->folioIdsBySubmission($candidates->pluck('id'));

        return $summaries->mapWithKeys(fn (EInvoiceSubmission $summary): array => [$summary->id => $candidates->first(
            fn (EInvoiceSubmission $candidate): bool => $candidate->property_id === $summary->property_id
                && $candidate->id > $summary->id
                && array_intersect($candidateStays[$candidate->id] ?? [], $stayIds[$summary->id] ?? []) !== [],
        )])->filter()->all();
    }

    /**
     * @param  Collection<int, EInvoiceSubmission>  $invoices
     * @return array<int, EInvoiceSubmission>
     */
    private function arInvoices(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $candidates = EInvoiceSubmission::query()
            ->whereIn('ar_invoice_id', $invoices->pluck('ar_invoice_id')->unique())
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->active()
            ->orderByDesc('id')
            ->get();

        return $invoices->mapWithKeys(fn (EInvoiceSubmission $invoice): array => [$invoice->id => $candidates->first(
            fn (EInvoiceSubmission $candidate): bool => $candidate->ar_invoice_id === $invoice->ar_invoice_id,
        )])->filter()->all();
    }

    /**
     * @param  Collection<int, EInvoiceSubmission>  $stays
     * @return array<int, EInvoiceSubmission>
     */
    private function stays(Collection $stays): array
    {
        if ($stays->isEmpty()) {
            return [];
        }

        $folioIds = $stays->pluck('folio_id')->unique()->values();
        $own = EInvoiceSubmission::query()
            ->whereIn('folio_id', $folioIds)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->active()
            ->get();
        $summaries = EInvoiceSubmission::query()
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->active()
            ->whereHas('folios', fn ($folios) => $folios->whereIn('folios.id', $folioIds))
            ->get();
        $summaryStays = $this->folioIdsBySubmission($summaries->pluck('id'));
        $candidates = $own->concat($summaries)->unique('id')->sortByDesc('id')->values();

        return $stays->mapWithKeys(fn (EInvoiceSubmission $stay): array => [$stay->id => $candidates->first(
            fn (EInvoiceSubmission $candidate): bool => $candidate->property_id === $stay->property_id
                && ($candidate->folio_id === $stay->folio_id || in_array($stay->folio_id, $summaryStays[$candidate->id] ?? [], true)),
        )])->filter()->all();
    }

    /**
     * @param  Collection<int, int>  $submissionIds
     * @return array<int, list<int>> Folio ids by monthly summary id.
     */
    private function folioIdsBySubmission(Collection $submissionIds): array
    {
        if ($submissionIds->isEmpty()) {
            return [];
        }

        $folioIds = [];
        foreach (DB::table('e_invoice_submission_folios')->whereIn('submission_id', $submissionIds)->get(['submission_id', 'folio_id']) as $row) {
            $folioIds[(int) $row->submission_id][] = (int) $row->folio_id;
        }

        return $folioIds;
    }
}
