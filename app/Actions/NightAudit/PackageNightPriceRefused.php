<?php

namespace App\Actions\NightAudit;

use App\Actions\Folio\PostingResult;
use RuntimeException;

/**
 * Raised when Retry refuses after the prices were written, so the transaction rolls them back. It never leaves the Action.
 */
class PackageNightPriceRefused extends RuntimeException
{
    public function __construct(public readonly PostingResult $result)
    {
        parent::__construct((string) $result->refusal);
    }
}
