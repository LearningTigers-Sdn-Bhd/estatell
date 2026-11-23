<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * The fields of an outlet form. `SavePosOutlet` uppercases the code.
 */
class PosOutletData extends Data
{
    public function __construct(
        public string $name,
        public string $code,
        public int $defaultChargeItemId,
        public bool $active = true,
    ) {}
}
