<?php

namespace App\Actions\Folio;

use App\Actions\Reservations\ReservationResult;
use App\Enums\ArInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\ArInvoice;
use App\Models\Folio;
use App\Models\Reservation;
use App\Services\Folio\MasterPaymentsHeld;

class FollowCompanyOnMasterFolio
{
    public function __construct(private MasterPaymentsHeld $masterPaymentsHeld) {}

    /**
     * Point the active Master folio's payer at a new company (null clears it). The Master folio's charges go with it:
     * this is how staff fix a wrong company. It returns a refusal and changes nothing when the Master folio holds
     * money (`master_has_payments`, with the amount) or has an AR invoice that is not Void (`master_has_ar_invoice`,
     * with the invoice number). It returns null when the payer moved. No Master folio is a success, because
     * `SyncRoutingRules` creates it with the reservation's company. Call it before the reservation's `company_id`
     * changes, inside the caller's transaction. See docs/15-city-ledger/01-companies.md.
     */
    public function __invoke(Reservation $reservation, ?int $companyId): ?ReservationResult
    {
        $master = Folio::query()
            ->where('reservation_id', $reservation->id)
            ->where('type', FolioType::Master)
            ->where('status', '<>', FolioStatus::Voided)
            ->oldest('id')
            ->lockForUpdate()
            ->first();

        if ($master === null) {
            return null;
        }

        $held = $this->masterPaymentsHeld->forReservation($reservation);

        if (bccomp($held, '0', 2) > 0) {
            return ReservationResult::refused(ReservationResult::REFUSAL_MASTER_HAS_PAYMENTS, detail: $held);
        }

        $invoice = ArInvoice::query()->where('folio_id', $master->id)->where('status', '<>', ArInvoiceStatus::Void)->oldest('id')->first();

        if ($invoice !== null) {
            return ReservationResult::refused(ReservationResult::REFUSAL_MASTER_HAS_AR_INVOICE, detail: $invoice->number);
        }

        $master->payer_company_id = $companyId;
        $master->save();

        return null;
    }
}
