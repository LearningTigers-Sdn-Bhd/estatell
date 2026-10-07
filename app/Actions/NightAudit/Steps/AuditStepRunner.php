<?php

namespace App\Actions\NightAudit\Steps;

use App\Models\NightAudit;
use Carbon\CarbonImmutable;

interface AuditStepRunner
{
    /**
     * Run the step for the business date of the audit. A unit the step cannot resolve becomes an exception row and
     * counts as failed. An error that stops the whole step is thrown, and `RunNightAudit` records it.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome;
}
