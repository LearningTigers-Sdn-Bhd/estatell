<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * The guest's bill and what they can do with it. `due` is what they still owe (never negative) and `credit` is money the
 * property holds for them, for example a security deposit, which the front desk gives back. `canPay` is true while
 * something is due. `canCheckOut` is true when the bill is settled and the check-out window is open; `checkOutOpensOn`
 * says when it opens otherwise. `checkOutAsked` is true while the guest's request for the front desk to check them out is open.
 */
class ConciergeBillData extends Data
{
    /**
     * @param  list<ConciergeBillLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public string $balance,
        public string $due,
        public string $credit,
        public string $currency,
        public bool $canPay,
        public bool $canCheckOut,
        public ?string $checkOutOpensOn,
        public bool $checkOutAsked = false,
    ) {}
}
