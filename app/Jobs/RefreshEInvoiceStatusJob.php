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

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120, 300];

    public function __construct(public int $submissionId) {}

    public function handle(RefreshEInvoiceStatus $refreshEInvoiceStatus): void
    {
        $submission = EInvoiceSubmission::query()->find($this->submissionId);

        if ($submission === null || empty($submission->uuid)) {
            return;
        }

        if ($submission->status !== EInvoiceStatus::Submitted && $submission->status !== EInvoiceStatus::Pending) {
            return;
        }

        $refreshed = $refreshEInvoiceStatus($submission);

        if ($refreshed->status === EInvoiceStatus::Submitted && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
        }
    }
}
