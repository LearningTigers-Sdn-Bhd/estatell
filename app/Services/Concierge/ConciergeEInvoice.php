<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergeEInvoiceBuyerData;
use App\Data\Concierge\ConciergeEInvoiceData;
use App\Enums\ConciergeEInvoiceState;
use App\Enums\EInvoiceStatus;
use App\Features\EInvoicing;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Services\EInvoice\BuyerResolver;
use App\Services\EInvoice\FolioEInvoiceLock;
use App\Services\EInvoice\MyInvoisClientFactory;
use App\Services\EInvoice\SubmissionRules;
use App\Services\Guests\CountryOptions;
use Laravel\Pennant\Feature;

/**
 * What a guest can do about the e-invoice of a stay. A guest asks for their own e-invoice for the stay's closed folio, in
 * the month the folio was closed: later the stay is in the monthly summary. It reads only. See
 * docs/30-concierge/01-overview.md#e-invoice-request.
 */
class ConciergeEInvoice
{
    private const string TIMEZONE = 'Asia/Kuala_Lumpur';

    public function __construct(
        private ConciergeDocuments $documents,
        private FolioEInvoiceLock $lock,
        private SubmissionRules $submissionRules,
        private CountryOptions $countries,
    ) {}

    /**
     * The folio an e-invoice is asked for: the first closed folio of the stay with an invoice number.
     */
    public function folio(ReservationUnit $stay): ?Folio
    {
        return $this->documents->invoiceFolio($stay);
    }

    public function state(?Folio $folio): ConciergeEInvoiceState
    {
        if ($folio === null) {
            return ConciergeEInvoiceState::AfterCheckOut;
        }

        $held = $this->lock->for($folio);

        if ($held !== null) {
            return match (true) {
                $held->is_consolidated => ConciergeEInvoiceState::InSummary,
                $held->status === EInvoiceStatus::Valid => ConciergeEInvoiceState::Valid,
                default => ConciergeEInvoiceState::Processing,
            };
        }

        $property = $folio->property()->with('eInvoiceSetting')->firstOrFail();
        $setting = $property->eInvoiceSetting;

        if ($folio->payer_company_id !== null
            || $setting === null
            || ! $setting->enabled
            || ! Feature::for($property)->active(EInvoicing::class)
            || ! MyInvoisClientFactory::isReady($setting)) {
            return ConciergeEInvoiceState::Unavailable;
        }

        return $folio->closed_at !== null && now()->greaterThan($folio->closed_at->setTimezone(self::TIMEZONE)->endOfMonth())
            ? ConciergeEInvoiceState::WindowClosed
            : ConciergeEInvoiceState::Open;
    }

    /**
     * Whether the stay screen should link to the e-invoice page.
     */
    public function isOffered(ReservationUnit $stay): bool
    {
        return $this->state($this->folio($stay))->isOffered();
    }

    public function for(ReservationUnit $stay): ConciergeEInvoiceData
    {
        $folio = $this->folio($stay);
        $state = $this->state($folio);
        $held = $folio === null ? null : $this->lock->for($folio);
        $last = $folio === null ? null : EInvoiceSubmission::query()->where('folio_id', $folio->id)->latest('id')->first();
        $rejected = $state === ConciergeEInvoiceState::Open && $last?->status === EInvoiceStatus::Invalid && $last->requested_by_guest;

        return new ConciergeEInvoiceData(
            state: $state,
            title: $state->title(),
            message: $state->message(),
            invoiceNumber: $held !== null && ! $held->is_consolidated ? $held->internal_id : $folio?->invoice_number,
            viewUrl: $state === ConciergeEInvoiceState::Valid && $held !== null ? $this->submissionRules->validationUrl($held) : null,
            buyer: $state === ConciergeEInvoiceState::Open ? $this->buyer($folio, $rejected ? $last : null) : null,
            rejection: $rejected ? __('LHDN could not accept your last request. Check your ID number and tax number, then send it again.') : null,
        );
    }

    /**
     * The form's starting values: what the guest typed the last time LHDN refused, else only the name, country, city,
     * state and postcode of the folio's payer. Identifiers and contact details are not prefilled (`ConciergeEInvoiceBuyerData`).
     */
    private function buyer(Folio $folio, ?EInvoiceSubmission $rejected): ConciergeEInvoiceBuyerData
    {
        $buyer = $rejected->buyer_snapshot ?? BuyerResolver::forFolio($folio);
        $buyer['country_code'] = $this->countries->alpha2($buyer['country_code'] ?? null) ?? 'MY';

        if (($buyer['tin'] ?? '') === BuyerResolver::FOREIGN_BUYER_TIN) {
            $buyer['tin'] = '';
        }

        return ConciergeEInvoiceBuyerData::fromBuyer($buyer, keepTyped: $rejected !== null);
    }
}
