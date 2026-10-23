<?php

namespace App\Data\Accounting;

use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\TransactionCode;
use Spatie\LaravelData\Data;

/**
 * One transaction code and the accounting account it posts to.
 */
class AccountingMappingData extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $kindLabel,
        public ?string $accountCode,
        public ?string $hint,
    ) {}

    public static function fromModel(TransactionCode $code): self
    {
        return new self($code->id, $code->code, $code->name, $code->kind->label(), $code->gl_account_code, self::hint($code));
    }

    /**
     * What else the account is used for when company billing is sent, so the hotel maps it on purpose.
     */
    private static function hint(TransactionCode $code): ?string
    {
        if ($code->system_key === SystemTransactionCode::DirectBillPayment->value) {
            return __('Also where company invoices post. Use a city ledger clearing account.');
        }

        return $code->kind === TransactionKind::Payment
            ? __('Also the bank account for company payments made this way.')
            : null;
    }
}
