<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * One line of a statement of account. `amount` is positive for an invoice and negative for a credit note or a
 * payment. `balance` is the running balance after the line.
 */
class StatementLineData extends Data
{
    public function __construct(
        public string $date,
        public string $kind,
        public string $kindLabel,
        public string $number,
        public ?string $description,
        public string $amount,
        public string $balance,
    ) {}
}
