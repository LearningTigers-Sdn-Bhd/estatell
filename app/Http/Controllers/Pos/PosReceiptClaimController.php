<?php

namespace App\Http\Controllers\Pos;

use App\Actions\EInvoice\EInvoiceResult;
use App\Actions\Pos\ClaimPosReceiptEInvoice;
use App\Actions\Pos\ConfirmPosReceiptClaim;
use App\Data\Property\PropertySummaryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ClaimPosReceiptRequest;
use App\Http\Requests\Pos\ConfirmPosReceiptClaimRequest;
use App\Models\PosOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a guest opens from the QR on a walk-in receipt, to get an e-invoice in their own name. No login. Holding the slip
 * is the proof: the guest gives its exact total and date, then the buyer details. The order's totals and lines are never
 * shown, and nothing of the buyer is logged. Every route is throttled by IP and the order counts its own tries.
 */
class PosReceiptClaimController extends Controller
{
    public function show(Request $request, ConfirmPosReceiptClaim $confirm, string $token): Response
    {
        $order = strlen($token) === 32 ? PosOrder::query()->where('receipt_claim_token', $token)->with(['property', 'outlet'])->first() : null;

        abort_if($order === null, 404);

        $closed = $order->receipt_claim_attempts >= ConfirmPosReceiptClaim::MAX_ATTEMPTS || $confirm->closed($order);

        return Inertia::render('receipt/claim', [
            'token' => $token,
            'property' => PropertySummaryData::fromModel($order->property, forGuest: true),
            'outletName' => $order->outlet->name,
            'step' => $closed ? 'closed' : ($request->session()->get($this->key($token)) === true ? 'details' : 'confirm'),
            'claimed' => (bool) $request->session()->get('pos_receipt_claimed'),
        ]);
    }

    public function confirm(ConfirmPosReceiptClaimRequest $request, ConfirmPosReceiptClaim $confirm, string $token): RedirectResponse
    {
        $result = $confirm($token, (string) $request->validated('total'), (string) $request->validated('date'));

        abort_if($result['refusal'] === 'receipt_not_found', 404);

        if ($result['refusal'] === null) {
            $request->session()->put($this->key($token), true);
        } else {
            Inertia::flash('toast', ['type' => 'error', 'message' => $this->message($result['refusal'])]);
        }

        return to_route('pos.receipt-claim.show', ['token' => $token]);
    }

    public function claim(ClaimPosReceiptRequest $request, ClaimPosReceiptEInvoice $claim, string $token): RedirectResponse
    {
        if ($request->session()->get($this->key($token)) !== true) {
            return to_route('pos.receipt-claim.show', ['token' => $token]);
        }

        $result = $claim($token, $request->validated());

        abort_if($result === 'receipt_not_found', 404);

        if ($result instanceof EInvoiceResult && $result->ok) {
            $request->session()->forget($this->key($token));
            $request->session()->flash('pos_receipt_claimed', true);
        } else {
            Inertia::flash('toast', ['type' => 'error', 'message' => $result instanceof EInvoiceResult ? (string) $result->message : $this->message($result)]);
        }

        return to_route('pos.receipt-claim.show', ['token' => $token]);
    }

    private function key(string $token): string
    {
        return 'pos_receipt_claim.'.hash('sha256', $token);
    }

    private function message(string $refusal): string
    {
        return match ($refusal) {
            'receipt_attempts_exceeded' => __('Too many tries. Please ask the staff for help.'),
            'receipt_closed' => __('This receipt can no longer be claimed here.'),
            default => __('That total and date do not match the receipt. Check the slip and try again.'),
        };
    }
}
