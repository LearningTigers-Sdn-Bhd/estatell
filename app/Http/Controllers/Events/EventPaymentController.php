<?php

namespace App\Http\Controllers\Events;

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundFolioCredit;
use App\Actions\Payments\RefundHeldPayment;
use App\Actions\Payments\VoidPayment;
use App\Data\Payments\PaymentQuoteData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\QuotePaymentRequest;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Requests\Payments\StoreRefundRequest;
use App\Http\Requests\Payments\StoreVoidRequest;
use App\Models\Event;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Services\Payments\PaymentQuote;
use App\Services\Payments\PaymentReceipt;
use App\Services\Payments\PaymentRefusals;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * Record payment and Refund on the event folio. See docs/33-facilities/03-events-and-event-folios.md#deposits.
 */
class EventPaymentController extends Controller
{
    /**
     * Quote a payment: the surcharge, its tax and the total. It writes nothing. A method of another property is a 404.
     */
    public function quote(QuotePaymentRequest $request, Property $property, Event $event, PaymentQuote $paymentQuote, PaymentRefusals $refusals): JsonResponse
    {
        $method = PaymentMethod::query()->where('property_id', $property->id)->with('surchargeChargeItem.transactionCode')->findOrFail($request->integer('payment_method_id'));
        $result = $paymentQuote->quoteFor($property, $event->payerGuest?->nationality_code, $method, $request->string('amount')->toString());

        return response()->json(PaymentQuoteData::fromResult($result, $result->isRefused() ? $refusals->message((string) $result->refusal) : null));
    }

    /**
     * Record a payment on the event folio. A refusal goes back to the Dialog as an error on the matching field.
     */
    public function store(StorePaymentRequest $request, Property $property, Event $event, Folio $folio, RecordPayment $recordPayment, PaymentRefusals $refusals): RedirectResponse
    {
        $result = $recordPayment->forEvent($request->user(), $folio, $request->toPaymentData());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $event))->withErrors($refusals->errors((string) $result->refusal));
        }

        $payment = $result->payment;
        $receipt = $payment?->receipt_number;

        Inertia::flash('toast', ['type' => 'success', 'message' => $receipt === null
            ? __('Payment of :amount recorded.', ['amount' => $payment?->amount])
            : __('Payment of :amount recorded. Receipt :receipt.', ['amount' => $payment->amount, 'receipt' => $receipt])]);

        return redirect()->to($this->page($property, $event));
    }

    /**
     * Refund part or all of a payment. A refusal goes back to the Dialog as an error.
     */
    public function refund(StoreRefundRequest $request, Property $property, Event $event, Folio $folio, Payment $payment, RefundHeldPayment $refundHeldPayment, PaymentRefusals $refusals): RedirectResponse
    {
        $result = $refundHeldPayment($request->user(), $payment, $request->string('amount')->toString(), $request->reference(), $request->string('idempotency_key')->toString(), methodId: $request->methodId(), reason: $request->reason(), notes: $request->notes());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $event))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund of :amount recorded.', ['amount' => $result->payment?->amount])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Void a payment taken today by mistake. A refusal goes back as an error.
     */
    public function void(StoreVoidRequest $request, Property $property, Event $event, Folio $folio, Payment $payment, VoidPayment $voidPayment, PaymentRefusals $refusals): RedirectResponse
    {
        $result = $voidPayment($request->user(), $payment, $request->string('idempotency_key')->toString(), $request->notes());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $event))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment of :amount voided.', ['amount' => $result->payment?->amount])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Give the folio's credit back from its payments, for example a deposit after a cancelled event.
     */
    public function refundCredit(StoreRefundRequest $request, Property $property, Event $event, Folio $folio, RefundFolioCredit $refundFolioCredit, PaymentRefusals $refusals): RedirectResponse
    {
        $result = $refundFolioCredit($request->user(), $folio, $request->string('amount')->toString(), $request->methodId(), $request->reason(), $request->string('idempotency_key')->toString());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $event))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund of :amount recorded.', ['amount' => $request->string('amount')->toString()])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * The receipt of a payment as an A4 PDF shown inline. A refund has no receipt (404).
     */
    public function receipt(Property $property, Event $event, Folio $folio, Payment $payment, PaymentReceipt $paymentReceipt, PrintedPdf $printedPdf): Response
    {
        abort_if($payment->refund_of_id !== null || $payment->receipt_number === null, 404);

        $receipt = $paymentReceipt->forPayment($payment);

        return $printedPdf->make($property, 'pdf.receipt', ['receipt' => $receipt])
            ->stream('receipt-'.$receipt->receiptNumber.'.pdf');
    }

    private function page(Property $property, Event $event): string
    {
        return route('events.show', ['property' => $property, 'event' => $event]);
    }
}
