<?php

namespace App\Actions\Transfers;

use RuntimeException;

/**
 * Thrown inside a transfer transaction when the charge refuses, so the transfer write and the charge rows roll back. The
 * Action catches it and returns the refusal. It never leaves the Action.
 */
class TransferRefused extends RuntimeException
{
    public function __construct(public readonly string $refusal)
    {
        parent::__construct($refusal);
    }
}
