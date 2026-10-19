<?php

namespace App\Actions\Folio;

use RuntimeException;

/**
 * Thrown inside the `PostManualBatch` transaction to roll it back; the Action catches it and returns the result.
 */
class ManualBatchRefusal extends RuntimeException
{
    public function __construct(public readonly ManualBatchResult $result)
    {
        parent::__construct('A manual batch row was refused.');
    }
}
