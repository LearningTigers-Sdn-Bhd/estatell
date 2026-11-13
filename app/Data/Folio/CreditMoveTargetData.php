<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * A folio that can take credit in **Move credit…**: its name with its payer, and what it `owes` (its balance).
 */
class CreditMoveTargetData extends Data
{
    public function __construct(
        public int $folioId,
        public string $label,
        public string $owes,
    ) {}
}
