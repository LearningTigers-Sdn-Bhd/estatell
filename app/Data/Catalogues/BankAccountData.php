<?php

namespace App\Data\Catalogues;

use App\Models\PropertyBankAccount;
use Spatie\LaravelData\Data;

/** One row of the bank account list and the values of its edit form. */
class BankAccountData extends Data
{
    public function __construct(
        public int $id,
        public string $bankName,
        public string $accountName,
        public string $accountNumber,
        public ?string $swiftCode,
        public bool $active,
    ) {}

    public static function fromModel(PropertyBankAccount $bankAccount): self
    {
        return new self(
            id: $bankAccount->id,
            bankName: $bankAccount->bank_name,
            accountName: $bankAccount->account_name,
            accountNumber: $bankAccount->account_number,
            swiftCode: $bankAccount->swift_code,
            active: $bankAccount->active,
        );
    }
}
