<?php

namespace App\Actions\Pos;

use RuntimeException;

/**
 * Thrown inside an Action's transaction to stop and roll it back with a refusal code, after some rows were already
 * written. The Action catches it and returns `PosActionResult::refused()`, so a refusal still writes nothing.
 */
class PosRefusedException extends RuntimeException
{
    public function __construct(public readonly string $refusal)
    {
        parent::__construct($refusal);
    }
}
