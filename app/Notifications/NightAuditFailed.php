<?php

namespace App\Notifications;

use App\Enums\AuditStepStatus;
use App\Enums\NotificationType;
use App\Models\NightAudit;
use App\Services\NightAudit\AuditSummary;

/**
 * The night audit failed. It names the step and the error, and the next run resumes at that step.
 */
class NightAuditFailed extends NightAuditResultNotification
{
    public function __construct(NightAudit $audit)
    {
        parent::__construct($audit, NotificationType::NightAuditFailed);
    }

    protected function subject(AuditSummary $summary): string
    {
        return __('Night audit failed for :date', ['date' => $summary->businessDate->toFormattedDateString()]);
    }

    protected function lines(AuditSummary $summary): array
    {
        $step = $this->audit->steps()->where('status', AuditStepStatus::Failed->value)->orderByDesc('id')->first();

        return [
            __('The audit stopped at the step ":step".', ['step' => $step?->step->label() ?? __('Unknown')]),
            __('Error: :error', ['error' => $step?->error_message ?? __('Unknown')]),
            __('The date is still open. Run the audit again to resume at this step. Nothing is posted twice.'),
        ];
    }
}
