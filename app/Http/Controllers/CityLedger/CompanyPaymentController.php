<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\AllocateArPayment;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\ReverseAllocation;
use App\Actions\CityLedger\VoidArPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\AllocateArPaymentRequest;
use App\Http\Requests\CityLedger\RecordArPaymentRequest;
use App\Http\Requests\CityLedger\ReverseAllocationRequest;
use App\Models\ArAllocation;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Property;
use App\Services\CityLedger\ArPaymentReceipt;
use App\Services\CityLedger\ArPaymentRefusals;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actions of a company's Payments tab. The tab itself is `CompanyController::payments`. Routes use scoped
 * bindings, so a payment or allocation of another company is a 404. See docs/15-city-ledger/03-ar-payments.md.
 */
class CompanyPaymentController extends Controller
{
    /**
     * Record a payment, optionally matched to the oldest open invoices. A refusal goes back to the Dialog as an error
     * on the matching field.
     */
    public function store(RecordArPaymentRequest $request, Property $property, Company $company, RecordArPayment $recordArPayment, ArPaymentRefusals $refusals): RedirectResponse
    {
        $result = $recordArPayment($request->user(), $company, $request->paymentData(), $request->shouldAllocate());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $company))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result->replayed
            ? __('Payment :receipt was already recorded.', ['receipt' => $result->payment?->receipt_number])
            : __('Payment recorded. Receipt :receipt.', ['receipt' => $result->payment?->receipt_number])]);

        return back(fallback: $this->page($property, $company));
    }

    public function allocate(AllocateArPaymentRequest $request, Property $property, Company $company, ArPayment $payment, AllocateArPayment $allocateArPayment, ArPaymentRefusals $refusals): RedirectResponse
    {
        $result = $allocateArPayment($request->user(), $payment, $request->amounts());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $company))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment allocated.')]);

        return back(fallback: $this->page($property, $company));
    }

    public function reverse(ReverseAllocationRequest $request, Property $property, Company $company, ArPayment $payment, ArAllocation $allocation, ReverseAllocation $reverseAllocation, ArPaymentRefusals $refusals): RedirectResponse
    {
        $result = $reverseAllocation($request->user(), $allocation, $request->string('reason')->toString());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $company))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allocation reversed.')]);

        return back(fallback: $this->page($property, $company));
    }

    public function void(Request $request, Property $property, Company $company, ArPayment $payment, VoidArPayment $voidArPayment, ArPaymentRefusals $refusals): RedirectResponse
    {
        $result = $voidArPayment($request->user(), $payment);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Payment :receipt voided.', ['receipt' => $payment->receipt_number])]);

        return back(fallback: $this->page($property, $company));
    }

    /**
     * The receipt as an A4 PDF, shown inline. It is rendered on demand and not stored.
     */
    public function receipt(Property $property, Company $company, ArPayment $payment, ArPaymentReceipt $arPaymentReceipt): Response
    {
        $receipt = $arPaymentReceipt->forPayment($payment);

        return Pdf::loadView('pdf.ar-receipt', ['receipt' => $receipt])
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true)
            ->stream('receipt-'.$receipt->receiptNumber.'.pdf');
    }

    private function page(Property $property, Company $company): string
    {
        return route('finance.city-ledger.companies.payments', ['property' => $property, 'company' => $company]);
    }
}
