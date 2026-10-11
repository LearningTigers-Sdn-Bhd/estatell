<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The posted balance of one folio before and after the move in the preview.
 */
class MoveBalanceData extends Data
{
    public function __construct(
        public int $folioId,
        public string $before,
        public string $after,
    ) {}
}
