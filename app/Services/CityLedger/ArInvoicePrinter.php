<?php

namespace App\Services\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Models\Folio;
use App\Services\Folio\DocumentLineGroups;
use App\Services\Folio\DocumentLines;
use App\Services\Folio\FolioDocumentRows;
use App\Services\Folio\FolioPaymentRows;
use App\Services\Property\AmountInWords;

/**
 * Reads an AR invoice or credit note and gathers what its PDF shows. An invoice prints the folio's lines through the
 * shared grouper with the Documents switches (the city ledger line left out), the Rooms block, the guest payments made
 * at the hotel and the amount due, which is the invoice amount. A credit note prints its reason and the invoice it
 * corrects and ignores the switches. It writes nothing. See docs/15-city-ledger/02-ar-invoices.md#pdf.
 */
class ArInvoicePrinter
{
    public function __construct(
        private CompanyBillTo $billTo,
        private FolioDocumentRows $documentRows,
        private FolioPaymentRows $paymentRows,
        private DocumentLines $documentLines,
        private AmountInWords $amountInWords,
    ) {}

    public function forInvoice(ArInvoice $invoice): ArInvoiceDocument
    {
        $invoice->loadMissing(['property.settings', 'property.printSetting', 'company', 'folio.reservation', 'folio.payerCompany', 'creditFor:id,number']);
        $company = $invoice->company;
        $isInvoice = $invoice->type === ArInvoiceType::Invoice;
        $issuedOn = $invoice->issued_on->format('j M Y');
        $dueOn = $invoice->due_on?->format('j M Y');
        $billToLines = $this->billTo->lines($company);
        $base = [
            'title' => $invoice->type->label(),
            'number' => $invoice->number,
            'isCreditNote' => ! $isInvoice,
            'companyName' => $company->name,
            'billToLines' => $billToLines,
            'issuedOn' => $issuedOn,
            'dueOn' => $dueOn,
            'creditFor' => $invoice->creditFor?->number,
            'reason' => $invoice->reason,
            'currency' => $invoice->property->settings?->currency,
            'total' => (string) $invoice->amount,
            'isVoid' => $invoice->status === ArInvoiceStatus::Void,
        ];

        if (! $isInvoice) {
            return new ArInvoiceDocument(...$base);
        }

        $settings = $invoice->property->printSetting;
        $folio = $invoice->folio;
        $groups = null;
        $rooms = [];
        $payments = [];
        $paid = '0.00';

        if ($folio instanceof Folio) {
            $property = $invoice->property;
            $units = $folio->reservation->units()->with(['roomType', 'guests.guest'])->orderBy('id')->get();
            $places = $this->documentRows->places($property, $units);
            $rows = $this->documentRows->for($property, $folio->lines()->orderBy('posting_date')->orderBy('id')->get(), $units, $places);
            $groups = $this->documentLines->group($rows, $settings);
            $rooms = $settings->show_unit_on_lines ? [] : $this->documentRows->roomLines($this->documentRows->unitsOnFolio($folio, $rows, $units), $places);
            [$payments, $paid] = $this->paymentRows->for($property, collect([$folio->id => $folio]), $company->name, withCityLedger: false);
        }

        $amountDue = (string) $invoice->amount;

        return new ArInvoiceDocument(
            ...$base,
            strip: $this->strip($company->name, $billToLines, $issuedOn, $dueOn, $folio?->reservation?->reference),
            rooms: $rooms,
            lines: $groups instanceof DocumentLineGroups && ($groups->groups !== [] || $groups->taxes !== []) ? $groups : null,
            payments: $payments,
            paid: $paid,
            amountDue: $amountDue,
            amountInWords: $settings->show_amount_in_words && $base['currency'] !== null ? $this->amountInWords->for($amountDue, $base['currency']) : null,
            paymentDetails: filled($settings->bank_details) ? $settings->bank_details : null,
        );
    }

    /**
     * Bill to, Issued, Due and Reservation, the cells that have a value.
     *
     * @param  list<string>  $billToLines
     * @return list<array{label: string, lines: list<string>}>
     */
    private function strip(string $companyName, array $billToLines, string $issuedOn, ?string $dueOn, ?string $reference): array
    {
        $cells = [
            [__('Bill to'), [$companyName, ...$billToLines]],
            [__('Issued'), [$issuedOn]],
            [__('Due'), [(string) $dueOn]],
            [__('Reservation'), [(string) $reference]],
        ];
        $strip = [];

        foreach ($cells as [$label, $lines]) {
            $lines = array_values(array_filter($lines, filled(...)));

            if ($lines !== []) {
                $strip[] = ['label' => $label, 'lines' => $lines];
            }
        }

        return $strip;
    }
}
