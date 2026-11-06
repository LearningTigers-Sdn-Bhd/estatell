<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\UploadPaymentProof;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UploadPaymentProofRequest;
use App\Models\CompanyUser;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Services\Portal\PortalBookings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payment proofs on a portal booking: the agent uploads one and opens the ones it sent. See
 * docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
class PortalPaymentProofController extends Controller
{
    public function store(UploadPaymentProofRequest $request, Property $property, int $reservation, PortalBookings $bookings, UploadPaymentProof $upload): RedirectResponse
    {
        $booking = $bookings->find($this->link($request)->company, $reservation);
        $proof = $upload($request->user(), $booking, $request->file('file'), (string) $request->validated('amount'), $request->validated('reference'));

        Inertia::flash('toast', $proof === null
            ? ['type' => 'error', 'message' => __('This booking is closed. Contact the property.')]
            : ['type' => 'success', 'message' => __('Payment proof sent. The property will confirm the payment.')]);

        return to_route('portal.bookings.show', ['property' => $property, 'reservation' => $reservation]);
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
