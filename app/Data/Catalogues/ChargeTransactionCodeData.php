<?php

namespace App\Data\Catalogues;

use App\Models\TransactionCode;
use Spatie\LaravelData\Data;

class ChargeTransactionCodeData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public bool $active,
    ) {}

    public static function fromModel(TransactionCode $transactionCode): self
    {
        return new self(
            id: $transactionCode->id,
            label: __(':code — :name', ['code' => $transactionCode->code, 'name' => $transactionCode->name]),
            active: $transactionCode->active,
        );
    }
}
