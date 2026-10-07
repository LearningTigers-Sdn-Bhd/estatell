<?php

namespace App\Jobs;

use App\Actions\NightAudit\NotifyNightAuditResult;
use App\Models\NightAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyNightAuditResultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A retry could send a message twice, and the audit does not depend on the message, so the job never retries.
     */
    public int $tries = 1;

    public function __construct(public NightAudit $audit) {}

    /**
     * Send the result of the audit with `NotifyNightAuditResult`. It runs after the audit ended, so an error here never
     * changes the audit.
     */
    public function handle(NotifyNightAuditResult $notifyNightAuditResult): void
    {
        $notifyNightAuditResult($this->audit);
    }
}
