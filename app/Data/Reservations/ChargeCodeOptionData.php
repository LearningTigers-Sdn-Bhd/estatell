<?php

namespace App\Data\Reservations;

use App\Models\TransactionCode;
use Spatie\LaravelData\Data;

/**
 * An active charge code, for a charge posted with an open amount.
 */
class ChargeCodeOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {}

    public static function fromModel(TransactionCode $code): self
    {
        return new self($code->id, $code->code, $code->name);
    }
}
