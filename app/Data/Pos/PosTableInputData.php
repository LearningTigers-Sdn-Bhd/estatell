<?php

namespace App\Data\Pos;

use App\Enums\PosTableShape;
use Spatie\LaravelData\Data;

/**
 * What the floor editor saves for one table: its code, seats, shape and its place and size on the plan.
 */
class PosTableInputData extends Data
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $label,
        public readonly int $seats,
        public readonly PosTableShape $shape,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $active = true,
    ) {}
}
