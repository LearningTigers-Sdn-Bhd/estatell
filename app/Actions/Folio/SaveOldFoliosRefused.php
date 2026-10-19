<?php

namespace App\Actions\Folio;

use RuntimeException;

/**
 * Thrown inside the `SaveOldFolios` transaction to roll it back when a posting is refused after the first write.
 */
class SaveOldFoliosRefused extends RuntimeException
{
    public function __construct(public readonly SaveOldFoliosResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}
