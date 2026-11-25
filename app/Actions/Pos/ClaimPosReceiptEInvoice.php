<?php

namespace App\Actions\Pos;

use App\Actions\EInvoice\EInvoiceResult;
use App\Actions\EInvoice\SubmitPosWalkInEInvoice;
use App\Models\PosOrder;
use Illuminate\Support\Facades\DB;

/**
 * The second step of a guest claiming an e-invoice for a walk-in receipt, after `ConfirmPosReceiptClaim` checked the slip: send
 * the sale to LHDN under the buyer's details. The claim is checked again here and counted as another try
 * (`receipt_attempts_exceeded`, `receipt_closed`), so the confirmation cannot be reused forever. The buyer's tax and ID
 * numbers go to the e-invoice only; nothing of them is logged or put on the activity log.
 */
class ClaimPosReceiptEInvoice
{
    public function __construct(private ConfirmPosReceiptClaim $confirm, private SubmitPosWalkInEInvoice $submitPosWalkInEInvoice) {}

    /**
     * @param  array<string, mixed>  $buyer
     */
    public function __invoke(string $token, array $buyer): EInvoiceResult|string
    {
        $order = DB::transaction(function () use ($token): PosOrder|string {
            $order = strlen($token) === 32 ? PosOrder::query()->where('receipt_claim_token', $token)->lockForUpdate()->first() : null;

            if ($order === null) {
                return 'receipt_not_found';
            }

            if ($order->receipt_claim_attempts >= ConfirmPosReceiptClaim::MAX_ATTEMPTS) {
                return 'receipt_attempts_exceeded';
            }

            $order->forceFill(['receipt_claim_attempts' => $order->receipt_claim_attempts + 1])->save();

            return $this->confirm->closed($order) ? 'receipt_closed' : $order;
        });

        return $order instanceof PosOrder ? ($this->submitPosWalkInEInvoice)($order, null, $buyer, requestedByGuest: true) : $order;
    }
}
