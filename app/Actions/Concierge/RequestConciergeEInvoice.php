<?php

namespace App\Actions\Concierge;

use App\Actions\EInvoice\EInvoiceResult;
use App\Actions\EInvoice\SubmitFolioEInvoice;
use App\Enums\ConciergeEInvoiceState;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeEInvoice;

class RequestConciergeEInvoice
{
    public const string REFUSAL_NOT_REQUESTABLE = 'not_requestable';

    public function __construct(private ConciergeEInvoice $eInvoice, private SubmitFolioEInvoice $submitFolioEInvoice) {}

    /**
     * A guest asks for their own e-invoice for the stay's closed folio, with the buyer details they typed. It goes to
     * LHDN through the same Action staff use, marked as a guest request. Refused, writing nothing, unless the page is open
     * for a request (`ConciergeEInvoiceState::Open`): the folio is closed, nothing is held on it yet, the property can
     * send e-invoices and the month the folio was closed is not over.
     *
     * @param  array<string, mixed>  $buyer  Validated buyer snapshot with an alpha-2 country.
     */
    public function __invoke(ReservationUnit $stay, array $buyer): EInvoiceResult
    {
        $folio = $this->eInvoice->folio($stay);

        if ($folio === null || $this->eInvoice->state($folio) !== ConciergeEInvoiceState::Open) {
            return EInvoiceResult::refused(self::REFUSAL_NOT_REQUESTABLE, __('An e-invoice cannot be requested for this stay here. Please ask the front desk.'));
        }

        return ($this->submitFolioEInvoice)($folio, null, true, $buyer);
    }
}
