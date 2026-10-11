<?php

namespace App\Services\Folio;

use App\Enums\ArInvoiceType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\ArInvoice;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The payment lines of some folios as a customer document prints them, with paid (net of refunds, without the city
 * ledger line) and billed to company, as the reservation list counts them. The booking summary and the folio invoice
 * use it. It only reads.
 */
class FolioPaymentRows
{
    /**
     * @param  Collection<int, Folio>  $folios  By folio id, with `payerCompany` loaded
     * @param  string|null  $fallbackCompany  The company named on a city ledger line when its folio has no payer company
     * @param  bool  $withCityLedger  False leaves the city ledger line out of the rows and the billed total, for the AR invoice
     * @return array{0: list<DocumentPayment>, 1: string, 2: string} The rows, paid and billed
     */
    public function for(Property $property, Collection $folios, ?string $fallbackCompany, bool $withCityLedger = true): array
    {
        $ids = $folios->keys()->all();
        $lines = FolioLine::query()
            ->whereIn('folio_id', $ids)
            ->where('kind', TransactionKind::Payment)
            ->with('transactionCode:id,system_key')
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();
        $payments = Payment::query()->whereIn('folio_id', $ids)->with('paymentMethod:id,name')->get()->keyBy('folio_line_id');
        $invoices = ArInvoice::query()
            ->where('property_id', $property->id)
            ->whereIn('folio_id', $ids)
            ->where('type', ArInvoiceType::Invoice)
            ->orderBy('id')
            ->pluck('number', 'folio_id');
        $paid = BigDecimal::zero();
        $billed = BigDecimal::zero();
        $rows = [];

        foreach ($lines as $line) {
            $payment = $payments->get($line->id);
            $method = $payment?->paymentMethod->name ?? $line->description;
            $date = CarbonImmutable::parse($line->posting_date->toDateString());

            if ($line->transactionCode?->system_key === SystemTransactionCode::DirectBillPayment->value) {
                if (! $withCityLedger) {
                    continue;
                }

                $billed = $billed->minus($line->amount);
                $company = $folios[$line->folio_id]->payerCompany?->name ?? $fallbackCompany;
                $invoice = $invoices->get($line->folio_id);
                $text = $company === null ? $line->description : __('Billed to :company', ['company' => $company]);
                $rows[] = new DocumentPayment($date, null, $invoice === null ? $text : $text.', '.__('invoice :number', ['number' => $invoice]), $line->amount);

                continue;
            }

            $paid = $paid->minus($line->amount);
            $rows[] = new DocumentPayment($date, $payment?->receipt_number, $payment?->refund_of_id === null ? $method : __('Refund').' · '.$method, $line->amount);
        }

        return [$rows, (string) $paid->toScale(2), (string) $billed->toScale(2)];
    }
}
