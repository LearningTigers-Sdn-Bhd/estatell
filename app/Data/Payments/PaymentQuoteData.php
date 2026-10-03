<?php

namespace App\Data\Payments;

use App\Services\Payments\PaymentQuoteResult;
use Spatie\LaravelData\Data;

/**
 * The answer of the payment quote route: the amount paid on the bill, the method's surcharge (without its tax), the
 * tax on the surcharge and the total to collect. A refused quote has no amounts and an `error` message.
 * See docs/14-payments/01-payments-and-refunds.md.
 */
class PaymentQuoteData extends Data
{
    public function __construct(
        public ?string $amount,
        public ?string $surcharge,
        public ?string $surchargeTax,
        public ?string $total,
        public ?string $error,
    ) {}

    public static function fromResult(PaymentQuoteResult $result, ?string $error): self
    {
        return new self(
            amount: $result->amount === null ? null : (string) $result->amount->getAmount(),
            surcharge: $result->surchargeNet === null ? null : (string) $result->surchargeNet->getAmount(),
            surchargeTax: $result->surchargeTax === null ? null : (string) $result->surchargeTax->getAmount(),
            total: $result->total === null ? null : (string) $result->total->getAmount(),
            error: $error,
        );
    }
}
