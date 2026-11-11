<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\UploadPaymentProof;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UploadPaymentProofRequest;
use App\Models\CompanyUser;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Notifications\PaymentSlipReceived;
use App\Services\Portal\PortalBookings;
use App\Services\Portal\PortalStatements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payment proofs on a portal booking: the agent uploads one and opens the ones it sent. See
 * docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
class PortalPaymentProofController extends Controller
{
    /**
     * Send a proof for the booking. Once the booking is invoiced it is saved as a company slip for that invoice, and the
     * staff who check slips are told.
     */
    public function store(UploadPaymentProofRequest $request, Property $property, int $reservation, PortalBookings $bookings, PortalStatements $statements, UploadPaymentProof $upload): RedirectResponse
    {
        $company = $this->link($request)->company;
        $booking = $bookings->find($company, $reservation);

        $proof = $upload($request->user(), $company, $booking, $request->file('file'), (string) $request->validated('amount'), $request->validated('reference'), $request->bankAccountId());

        if ($proof?->company_id !== null) {
            Notification::send($statements->slipReviewers($company), new PaymentSlipReceived($company->id, $company->name, $property->code, $property->name, $proof->amount));
        }

        Inertia::flash('toast', $proof === null
            ? ['type' => 'error', 'message' => __('This booking is closed. Contact the property.')]
            : ['type' => 'success', 'message' => __('Payment proof sent. The property will confirm the payment.')]);

        return to_route('portal.bookings.payments', ['property' => $property, 'reservation' => $reservation]);
    }

    public function file(Request $request, Property $property, int $reservation, PaymentProof $paymentProof, PortalBookings $bookings): StreamedResponse
    {
        $booking = $bookings->find($this->link($request)->company, $reservation);
        abort_unless($paymentProof->reservation_id === $booking->id, 404);

        return ($paymentProof->getFirstMedia(PaymentProof::FILE) ?? abort(404))->toInlineResponse($request);
    }

    private function link(Request $request): CompanyUser
    {
        $link = $request->attributes->get('portalLink');

        abort_unless($link instanceof CompanyUser, 404);

        return $link;
    }
}
