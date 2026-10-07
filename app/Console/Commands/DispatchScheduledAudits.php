<?php

namespace App\Console\Commands;

use App\Enums\AuditTrigger;
use App\Jobs\RunNightAuditJob;
use App\Services\NightAudit\DueAudits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('night-audit:dispatch-scheduled')]
#[Description('Queue the night audit of every property whose automatic audit is due')]
class DispatchScheduledAudits extends Command
{
    public function handle(DueAudits $dueAudits): int
    {
        foreach ($dueAudits->get() as $property) {
            RunNightAuditJob::dispatch($property, AuditTrigger::Scheduled);
        }

        return self::SUCCESS;
    }
}
