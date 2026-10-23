<?php

namespace App\Actions\CityLedger;

use App\Actions\Accounting\QueueCompanyBillingSync;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\FolioStatus;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\Folio;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class CreateArInvoiceFromFolio
{
    /**
     * Make the AR invoice of a Closed folio paid by a company. It takes the folio's `invoice_number`, the given amount
     * and `issued_on`, and is due `payment_terms_days` later (the same day without terms). A folio has one live
     * invoice: calling it again returns that invoice and writes nothing. `SettleToCityLedger` calls it inside its
     * transaction. See docs/15-city-ledger/02-ar-invoices.md.
     *
     * @throws InvalidArgumentException when the folio is not Closed, has no invoice number or has no company payer
     */
    public function __invoke(User $actor, Folio $folio, string $amount, CarbonImmutable $issuedOn): ArInvoice
    {
        if ($folio->status !== FolioStatus::Closed || $folio->invoice_number === null || $folio->payer_company_id === null) {
            throw new InvalidArgumentException('An AR invoice needs a Closed folio with an invoice number and a company payer.');
        }

        $existing = ArInvoice::query()
            ->where('folio_id', $folio->id)
            ->where('type', ArInvoiceType::Invoice)
            ->where('status', '<>', ArInvoiceStatus::Void)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $company = Company::query()->where('property_id', $folio->property_id)->findOrFail($folio->payer_company_id);

        $invoice = new ArInvoice([
            'company_id' => $company->id,
            'folio_id' => $folio->id,
            'type' => ArInvoiceType::Invoice,
            'number' => $folio->invoice_number,
            'issued_on' => $issuedOn,
            'due_on' => $issuedOn->addDays($company->payment_terms_days ?? 0),
            'amount' => $amount,
            'status' => ArInvoiceStatus::Open,
            'created_by' => $actor->id,
        ]);
        $invoice->property_id = $folio->property_id;
        $invoice->save();

        app(QueueCompanyBillingSync::class)($folio->property_id);

        activity()
            ->performedOn($company)
            ->causedBy($actor)
            ->event('ar_invoice.created')
            ->withProperties([
                'property_id' => $folio->property_id,
                'ar_invoice_id' => $invoice->id,
                'folio_id' => $folio->id,
                'number' => $invoice->number,
                'amount' => $amount,
                'due_on' => $invoice->due_on->toDateString(),
            ])
            ->log('AR invoice created');

        return $invoice;
    }
}
