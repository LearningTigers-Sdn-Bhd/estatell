<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergeDocumentData;
use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Services\FrontDesk\CheckOutSettlement;
use Illuminate\Support\Collection;

/**
 * The documents a guest can open for a stay: the booking summary, one receipt for each payment taken on the stay's own
 * folios, and the final invoice once the folio is closed. A refund, and a payment without a receipt number (an old
 * system one), has no receipt. It reads only.
 */
class ConciergeDocuments
{
    public function __construct(private CheckOutSettlement $settlement) {}

    /**
     * @return list<ConciergeDocumentData>
     */
    public function links(ReservationUnit $stay, string $token): array
    {
        $documents = [new ConciergeDocumentData(__('Booking summary'), route('concierge.documents.summary', ['token' => $token], absolute: false))];

        foreach ($this->receipts($stay) as $payment) {
            $documents[] = new ConciergeDocumentData(
                __('Receipt :number (:amount)', ['number' => $payment->receipt_number, 'amount' => $payment->amount]),
                route('concierge.documents.receipt', ['token' => $token, 'payment' => $payment->id], absolute: false),
            );
        }

        if ($this->invoiceFolio($stay) !== null) {
            $documents[] = new ConciergeDocumentData(__('Final invoice'), route('concierge.documents.invoice', ['token' => $token], absolute: false));
        }

        return $documents;
    }

    /**
     * The payments of the stay's own folios that have a receipt, oldest first.
     *
     * @return Collection<int, Payment>
     */
    public function receipts(ReservationUnit $stay)
    {
        return Payment::query()
            ->where('property_id', $stay->property_id)
            ->whereIn('folio_id', $this->settlement->folios($stay)->pluck('id'))
            ->whereNull('refund_of_id')
            ->whereNotNull('receipt_number')
            ->orderBy('id')
            ->get();
    }

    /**
     * The first closed folio of the stay that has an invoice number, or null while the folio is still open.
     */
    public function invoiceFolio(ReservationUnit $stay): ?Folio
    {
        return $this->settlement->folios($stay)->first(fn (Folio $folio): bool => $folio->status === FolioStatus::Closed && $folio->invoice_number !== null);
    }
}
