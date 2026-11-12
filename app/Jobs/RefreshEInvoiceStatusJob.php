<?php

namespace App\Jobs;

use App\Actions\EInvoice\RefreshEInvoiceStatus;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshEInvoiceStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Checks over about 25 minutes: long enough for LHDN to validate, and for a document whose send answer was lost
     * to pass the 10 minutes after which an empty look-up is reported.
     */
    public int $tries = 8;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120, 180, 300, 300, 600];

    public function __construct(public int $submissionId) {}

    public function handle(RefreshEInvoiceStatus $refreshEInvoiceStatus): void
    {
        $submission = EInvoiceSubmission::query()->find($this->submissionId);

        if ($submission === null || ($submission->status !== EInvoiceStatus::Submitted && $submission->status !== EInvoiceStatus::Pending)) {
            return;
        }

        $refreshed = $refreshEInvoiceStatus($submission);
        $waiting = $refreshed->status === EInvoiceStatus::Submitted || $refreshed->status === EInvoiceStatus::Pending;

        if ($waiting && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
        }
    }
}
