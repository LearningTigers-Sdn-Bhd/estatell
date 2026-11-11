<?php

namespace App\Data\Commissions;

use Spatie\LaravelData\Data;

/**
 * One voucher with its lines, totals, the bank account to pay and what an admin may do next.
 */
class PayoutVoucherData extends Data
{
    /**
     * @param  list<PayoutVoucherLineData>  $lines
     * @param  list<array{label: string, amount: string}>  $totals
     * @param  array{bankName: string, accountName: string, accountNumber: string, swiftCode: string|null}|null  $bankAccount
     */
    public function __construct(
        public PayoutVoucherRowData $voucher,
        public array $lines,
        public array $totals,
        public ?array $bankAccount,
        public ?string $approvedBy,
        public ?string $approvedAt,
        public ?string $paidOn,
        public ?string $paidReference,
        public bool $canApprove,
        public bool $canMarkPaid,
        public bool $canAdjust,
    ) {}
}
