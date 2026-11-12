<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\UploadCompanyPaymentSlip;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UploadPaymentProofRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Notifications\PaymentSlipReceived;
use App\Services\Portal\PortalStatements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payment slips for a company's city ledger account, on the portal's statements page. The company always comes from the
 * `portal` middleware, so another company's slip is a 404. See docs/15-city-ledger/03-ar-payments.md#payment-slips.
 */
class PortalPaymentSlipController extends Controller
{
    public function store(UploadPaymentProofRequest $request, Property $property, PortalStatements $statements, UploadCompanyPaymentSlip $upload): RedirectResponse
    {
        $company = $this->company($request);
        $page = route('portal.statements.slips', ['property' => $property]);

        if (! $statements->canSendSlip($company)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Your company pays per booking. Send the proof on the booking.')]);

            return to_route('portal.statements', ['property' => $property]);
        }

        $slip = $upload($request->user(), $company, $request->file('file'), (string) $request->validated('amount'), $request->validated('reference'), $request->bankAccountId());
        Notification::send($statements->slipReviewers($company), new PaymentSlipReceived($company->id, $company->name, $property->code, $property->name, $slip->amount));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment slip sent. The property will confirm the payment.')]);

        return redirect($page);
    }

    public function file(Request $request, Property $property, PaymentProof $paymentProof): StreamedResponse
    {
        abort_unless($paymentProof->company_id === $this->company($request)->id, 404);

        return ($paymentProof->getFirstMedia(PaymentProof::FILE) ?? abort(404))->toInlineResponse($request);
    }

    private function company(Request $request): Company
    {
        $link = $request->attributes->get('portalLink');

        abort_unless($link instanceof CompanyUser, 404);

        return $link->company;
    }
}
