<?php

namespace App\Data\StayView;

use App\Models\FolioLine;
use Spatie\LaravelData\Data;

/**
 * One posted folio line in the peek sheet. `amount` is a decimal string: charges and taxes are positive, payments
 * negative.
 */
class StayFolioLineData extends Data
{
    public function __construct(
        public int $id,
        public string $date,
        public string $description,
        public string $amount,
    ) {}

    public static function fromModel(FolioLine $line): self
    {
        return new self(
            id: $line->id,
            date: $line->posting_date->toDateString(),
            description: $line->description,
            amount: $line->amount,
        );
    }
}
