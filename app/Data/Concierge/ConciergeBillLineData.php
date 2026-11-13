<?php

namespace App\Data\Concierge;

use App\Models\FolioLine;
use Spatie\LaravelData\Data;

/**
 * One line of the guest's bill. `amount` is signed: charges and taxes are positive, payments negative.
 */
class ConciergeBillLineData extends Data
{
    public function __construct(
        public string $description,
        public string $amount,
        public string $kind,
        public string $date,
    ) {}

    public static function fromLine(FolioLine $line): self
    {
        return new self($line->description, $line->amount, $line->kind->value, $line->service_date->toDateString());
    }
}
