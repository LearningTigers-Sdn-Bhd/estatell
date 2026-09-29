<?php

namespace App\Data\Catalogues;

use App\Models\TransactionCode;
use Spatie\LaravelData\Data;

/** A charge code a Selected discount may target. */
class DiscountChargeData extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public bool $active,
    ) {}

    public static function fromModel(TransactionCode $transactionCode): self
    {
        return new self(
            id: $transactionCode->id,
            code: $transactionCode->code,
            name: $transactionCode->name,
            active: $transactionCode->active,
        );
    }
}
