<?php

namespace App\Services\NightAudit;

use RuntimeException;

/**
 * Raised when a planned document number is not above the last number of its sequence.
 * The sequence stays as it was.
 */
class PlannedNumberRefused extends RuntimeException
{
    public function __construct(public readonly int $planned, public readonly int $lastNumber)
    {
        parent::__construct("Planned document number {$planned} is not above the last issued number {$lastNumber}.");
    }
}
