<?php

namespace App\Actions\FrontDesk;

/**
 * One payment for all: the room that pays, how, and the amount the page showed. The server adds up the charges it
 * moves to that room (and the payer's own balance when its row is set to Collect) and refuses the save with
 * `amount_changed` when the total no longer equals `amount`. The payment uses `{uuid}:all`.
 */
class OnePayment
{
    public function __construct(
        public readonly int $payerUnitId,
        public readonly int $paymentMethodId,
        public readonly ?string $reference,
        public readonly string $amount,
        public readonly string $idempotencyKey,
    ) {}
}
