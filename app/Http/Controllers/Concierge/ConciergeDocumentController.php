<?php

namespace App\Http\Controllers\Concierge;

use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeDocuments;
use App\Services\Folio\FolioInvoice;
use App\Services\Payments\PaymentReceipt;
use App\Services\Property\PrintedPdf;
use App\Services\Reservations\BookingSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The PDFs a guest can open from the stay link: the booking summary, a payment receipt and the final invoice. They are
 * made by the same builders staff use, on demand, and store nothing. A guest sees only the documents of their own stay,
 * and only while it is checked in or checked out; any other document is a 404.
 */
class ConciergeDocumentController extends Controller
{
    public function summary(Request $request, string $token, BookingSummary $bookingSummary, PrintedPdf $printedPdf): Response
    {
        $stay = $this->stay($request);
        $property = $stay->property()->with('printSetting')->firstOrFail();
        $reservation = $stay->reservation()->firstOrFail();

        return $printedPdf->make($property, 'pdf.booking-summary', [
            'guestDocument' => true,
            'summary' => $bookingSummary->for($property, $reservation, true),
            'settings' => $property->printSetting,
        ])->stream('booking-summary-'.$reservation->reference.'.pdf');
    }

    public function receipt(Request $request, string $token, int $payment, ConciergeDocuments $documents, PaymentReceipt $paymentReceipt, PrintedPdf $printedPdf): Response
    {
        $stay = $this->stay($request);
        $found = $documents->receipts($stay)->firstWhere('id', $payment);

        abort_if($found === null, 404);

        $receipt = $paymentReceipt->forPayment($found);

        return $printedPdf->make($stay->property()->firstOrFail(), 'pdf.receipt', ['receipt' => $receipt, 'guestDocument' => true])
            ->stream('receipt-'.$receipt->receiptNumber.'.pdf');
    }

    public function invoice(Request $request, string $token, ConciergeDocuments $documents, FolioInvoice $folioInvoice, PrintedPdf $printedPdf): Response
    {
        $stay = $this->stay($request);
        $folio = $documents->invoiceFolio($stay);

        abort_if($folio === null, 404);

        $property = $stay->property()->with('printSetting')->firstOrFail();

        return $printedPdf->make($property, 'pdf.folio-invoice', [
            'guestDocument' => true,
            'invoice' => $folioInvoice->for($folio),
            'settings' => $property->printSetting,
        ])->stream('invoice-'.$folio->invoice_number.'.pdf');
    }

    /**
     * The stay of the link, when it is checked in or checked out.
     */
    private function stay(Request $request): ReservationUnit
    {
        /** @var ReservationUnit $stay */
        $stay = $request->attributes->get('concierge.stay');

        abort_unless(in_array($stay->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true), 404);

        return $stay;
    }
}
