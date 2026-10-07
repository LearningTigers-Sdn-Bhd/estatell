<?php

namespace App\Actions\NightAudit\Steps;

/**
 * How many units a night audit step resolved and how many it left as exceptions.
 */
class StepOutcome
{
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $failed = 0,
    ) {}
}
