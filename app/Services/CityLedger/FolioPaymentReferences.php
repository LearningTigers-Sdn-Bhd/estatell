<?php

namespace App\Services\CityLedger;

use App\Enums\PaymentStatus;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Finds money a company's bank reference was already recorded for, so the Record AR payment Dialog can warn before the
 * same transfer is counted twice. A reference matches when it is equal after trim, ignoring case; a blank reference
 * never matches. It looks at Completed folio payments (not refunds) on a folio the company pays or on any folio of a
 * reservation of the company, and at the company's AR payments that are not voided. It reads only and never blocks:
 * the answer is a warning text, or null. The warning names the oldest match. See docs/15-city-ledger/03-ar-payments.md#rules.
 */
class FolioPaymentReferences
{
    public function warningFor(Company $company, ?string $reference): ?string
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        $matches = $this->folioPayments($company, $reference)->concat($this->arPayments($company, $reference))
            ->sortBy([['date', 'asc'], ['order', 'asc']])
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }

        $first = $matches->first();
        $more = $matches->count() - 1;
        $where = $first['folio'] === null
            ? __('as AR payment :receipt', ['receipt' => $first['receipt']])
            : __('on folio :folio (receipt :receipt)', ['folio' => $first['folio'], 'receipt' => $first['receipt']]);
        $text = __('Reference :reference was already recorded :where on :date', [
            'reference' => $reference,
            'where' => $where,
            'date' => $first['date']->format('d M'),
        ]);

        return ($more > 0 ? $text.', '.__('and :count more.', ['count' => $more]) : $text.'.').' '.__('Check it is not the same money before you record it.');
    }

    /**
     * @return Collection<int, array{date: CarbonInterface, order: int, folio: string|null, receipt: string}>
     */
    private function folioPayments(Company $company, string $reference): Collection
    {
        return Payment::query()
            ->where('property_id', $company->property_id)
            ->whereNull('refund_of_id')
            ->where('status', PaymentStatus::Completed)
            ->whereRaw('lower(trim(reference)) = ?', [mb_strtolower($reference)])
            ->whereHas('folio', fn ($folios) => $folios
                ->where('payer_company_id', $company->id)
                ->orWhereHas('reservation', fn ($reservations) => $reservations->where('company_id', $company->id)))
            ->with('folio:id,number', 'folioLine:id,posting_date')
            ->get()
            ->map(fn (Payment $payment): array => [
                'date' => $payment->folioLine->posting_date,
                'order' => $payment->id,
                'folio' => $payment->folio->number,
                'receipt' => (string) $payment->receipt_number,
            ]);
    }

    /**
     * @return Collection<int, array{date: CarbonInterface, order: int, folio: string|null, receipt: string}>
     */
    private function arPayments(Company $company, string $reference): Collection
    {
        return ArPayment::query()
            ->where('property_id', $company->property_id)
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->whereRaw('lower(trim(reference)) = ?', [mb_strtolower($reference)])
            ->get()
            ->map(fn (ArPayment $payment): array => [
                'date' => $payment->received_on,
                'order' => 1_000_000_000 + $payment->id,
                'folio' => null,
                'receipt' => $payment->receipt_number,
            ]);
    }
}
