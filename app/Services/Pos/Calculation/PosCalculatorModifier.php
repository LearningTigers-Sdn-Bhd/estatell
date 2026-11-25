<?php

namespace App\Services\Pos\Calculation;

/**
 * A chosen modifier option at the price charged for it, after any free included choices.
 */
final readonly class PosCalculatorModifier
{
    public function __construct(public string $chargedPrice, public int $quantity = 1) {}
}
