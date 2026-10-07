<?php

namespace App\Actions\NightAudit\Steps;

use App\Actions\NightAudit\SnapshotStatistics;
use App\Models\NightAudit;
use Carbon\CarbonImmutable;

class SnapshotStatisticsStep implements AuditStepRunner
{
    public function __construct(private SnapshotStatistics $snapshotStatistics) {}

    /**
     * Snapshot the statistics and the revenue of the business date through `SnapshotStatistics`. A throw fails the
     * audit, and the next run resumes at this step.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        ($this->snapshotStatistics)($audit->property, $date);

        return new StepOutcome(1);
    }
}
