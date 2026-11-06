<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\AcceptCompanyPaymentSlip;
use App\Actions\CityLedger\ArPaymentResult;
use App\Actions\Payments\RejectPaymentProof;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\PaymentProof;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff check a slip a company sent for its account, on the company's Payments tab. Accept records a city ledger
 * payment, and Reject gives a reason the company sees. See docs/15-city-ledger/03-ar-payments.md#payment-slips.
 */
class CompanyPaymentSlipController extends Controller
{
    public function file(Request $request, Property $property, Company $company, PaymentProof $paymentProof): StreamedResponse
    {
        abort_unless($paymentProof->company_id === $company->id, 404);

        return ($paymentProof->getFirstMedia(PaymentProof::FILE) ?? abort(404))->toInlineResponse($request);
    }

    public function accept(Request $request, Property $property, Company $company, PaymentProof $paymentProof, AcceptCompanyPaymentSlip $accept): RedirectResponse
    {
        abort_unless($paymentProof->company_id === $company->id, 404);
        $validated = $request->validate([
            'payment_method_id' => ['required', 'integer', Rule::exists(PaymentMethod::class, 'id')->where('property_id', $property->id)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ]);

        $result = $accept($request->user(), $paymentProof, (int) $validated['payment_method_id'], number_format((float) $validated['amount'], 2, '.', ''));

        if ($result->isRefused()) {
            return back()->withErrors(['amount' => match ($result->refusal) {
                AcceptCompanyPaymentSlip::REFUSAL_NOT_SUBMITTED => __('This slip was already checked.'),
                ArPaymentResult::REFUSAL_REFERENCE_REQUIRED => __('This method needs a reference, and the slip has none. Reject it and ask for the reference, or pick another method.'),
                default => __('The payment could not be recorded (:code).', ['code' => $result->refusal]),
            }]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded from the slip.')]);

        return back();
    }

    public function reject(Request $request, Property $property, Company $company, PaymentProof $paymentProof, RejectPaymentProof $reject): RedirectResponse
    {
        abort_unless($paymentProof->company_id === $company->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! $reject($request->user(), $paymentProof, $validated['reason'])) {
            return back()->withErrors(['reason' => __('This slip was already checked.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Slip rejected. The company sees your reason.')]);

        return back();
    }
}
