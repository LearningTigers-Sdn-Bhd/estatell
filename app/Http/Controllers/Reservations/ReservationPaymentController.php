<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Payments\PaymentQuoteData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\QuotePaymentRequest;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Requests\Payments\StoreRefundRequest;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Payments\PaymentQuote;
use App\Services\Payments\PaymentReceipt;
use App\Services\Payments\PaymentRefusals;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * Record payment and Refund on the folio tab. See docs/14-payments/01-payments-and-refunds.md.
 */
class ReservationPaymentController extends Controller
{
    /**
     * Quote a payment: the surcharge, its tax and the total to collect. It writes nothing. A refused quote (an invalid
     * amount, a tax that cannot be calculated) still answers 200 with its message. A method of another property is a 404.
     */
    public function quote(QuotePaymentRequest $request, Property $property, Reservation $reservation, PaymentQuote $paymentQuote, PaymentRefusals $refusals): JsonResponse
    {
        $method = PaymentMethod::query()->where('property_id', $property->id)->with('surchargeChargeItem.transactionCode')->findOrFail($request->integer('payment_method_id'));
        $result = $paymentQuote->quote($reservation, $method, $request->string('amount')->toString());

        return response()->json(PaymentQuoteData::fromResult($result, $result->isRefused() ? $refusals->message((string) $result->refusal) : null));
    }

    /**
     * Record a payment on a folio. A refusal goes back to the Dialog as an error on the matching field.
     */
    public function storeForFolio(StorePaymentRequest $request, Property $property, Reservation $reservation, Folio $folio, RecordPayment $recordPayment, PaymentRefusals $refusals): RedirectResponse
    {
        $result = $recordPayment($request->user(), $reservation, $folio, null, $request->toPaymentData());

        return $this->recorded($result, $refusals, $property, $reservation, $folio->id);
    }

    /**
     * Record a payment on a unit with no folio yet (a "not started" entry). The payment creates the unit's folio, so
     * the redirect opens that folio.
     */
    public function storeForUnit(StorePaymentRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, RecordPayment $recordPayment, PaymentRefusals $refusals): RedirectResponse
    {
        $result = $recordPayment($request->user(), $reservation, null, $unit, $request->toPaymentData());

        return $this->recorded($result, $refusals, $property, $reservation, $result->payment?->folio_id);
    }

    /**
     * Refund part or all of a payment. A refusal goes back to the Dialog as an error.
     */
    public function refund(StoreRefundRequest $request, Property $property, Reservation $reservation, Folio $folio, Payment $payment, RefundPayment $refundPayment, PaymentRefusals $refusals): RedirectResponse
    {
        $result = $refundPayment($request->user(), $payment, $request->string('amount')->toString(), $request->reference(), $request->string('idempotency_key')->toString());
        $page = $this->page($property, $reservation, $folio->id);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund of :amount recorded.', ['amount' => $result->payment?->amount])]);

        return back(fallback: $page);
    }

    /**
     * The receipt of a payment as an A4 PDF, shown inline so staff can print or save it. It is rendered on demand and
     * not stored. Font subsetting keeps the file small (about 20 KB instead of 850 KB). A refund has no receipt (404).
     */
    public function receipt(Property $property, Reservation $reservation, Folio $folio, Payment $payment, PaymentReceipt $paymentReceipt, PrintedPdf $printedPdf): Response
    {
        abort_if($payment->refund_of_id !== null, 404);

        $receipt = $paymentReceipt->forPayment($payment);

        return $printedPdf->make($property, 'pdf.receipt', ['receipt' => $receipt])
            ->stream('receipt-'.$receipt->receiptNumber.'.pdf');
    }

    private function recorded(PaymentResult $result, PaymentRefusals $refusals, Property $property, Reservation $reservation, ?int $folioId): RedirectResponse
    {
        if ($result->isRefused()) {
            return back(fallback: route('reservations.folio', ['property' => $property, 'reservation' => $reservation]))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment of :amount recorded. Receipt :receipt.', [
            'amount' => $result->payment?->amount,
            'receipt' => $result->payment?->receipt_number,
        ])]);

        return redirect()->to($this->page($property, $reservation, $folioId));
    }

    private function page(Property $property, Reservation $reservation, ?int $folioId): string
    {
        return route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folioId]);
    }
}
