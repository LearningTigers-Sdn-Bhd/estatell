<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Payments\AcceptPaymentProof;
use App\Actions\Payments\RejectPaymentProof;
use App\Http\Controllers\Controller;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Payments\ProofPaymentMethods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An agent's payment proofs on the reservation page: open the file, accept it (which records the payment) or reject
 * it. See docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
class ReservationPaymentProofController extends Controller
{
    public function file(Request $request, Property $property, Reservation $reservation, PaymentProof $paymentProof): StreamedResponse
    {
        abort_unless($paymentProof->reservation_id === $reservation->id, 404);

        return ($paymentProof->getFirstMedia(PaymentProof::FILE) ?? abort(404))->toInlineResponse($request);
    }

    public function accept(Request $request, Property $property, Reservation $reservation, PaymentProof $paymentProof, AcceptPaymentProof $accept, ProofPaymentMethods $methods): RedirectResponse
    {
        abort_unless($paymentProof->reservation_id === $reservation->id, 404);
        $validated = $request->validate([
            'payment_method_id' => ['required', 'integer', Rule::in(array_column($methods->options($property), 'value'))],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ]);

        $result = $accept($request->user(), $paymentProof, (int) $validated['payment_method_id'], number_format((float) $validated['amount'], 2, '.', ''));

        if ($result->isRefused()) {
            return back()->withErrors(['amount' => match ($result->refusal) {
                AcceptPaymentProof::REFUSAL_NOT_SUBMITTED => __('This proof was already checked.'),
                AcceptPaymentProof::REFUSAL_NO_FOLIO => __('This booking has no open bill to record the payment on.'),
                default => __('The payment could not be recorded (:code).', ['code' => $result->refusal]),
            }]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded from the proof.')]);

        return back();
    }

    public function reject(Request $request, Property $property, Reservation $reservation, PaymentProof $paymentProof, RejectPaymentProof $reject): RedirectResponse
    {
        abort_unless($paymentProof->reservation_id === $reservation->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! $reject($request->user(), $paymentProof, $validated['reason'])) {
            return back()->withErrors(['reason' => __('This proof was already checked.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proof rejected. The agent sees your reason.')]);

        return back();
    }
}
