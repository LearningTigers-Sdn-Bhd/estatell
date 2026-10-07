<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\NightAudit;
use App\Services\NightAudit\AuditSummary;

/**
 * The night audit completed and left at least one open exception to review.
 */
class NightAuditExceptions extends NightAuditResultNotification
{
    public function __construct(NightAudit $audit)
    {
        parent::__construct($audit, NotificationType::NightAuditExceptions);
    }

    protected function subject(AuditSummary $summary): string
    {
        return trans_choice(':count exception to review after the night audit for :date|:count exceptions to review after the night audit for :date', $summary->exceptions->count(), [
            'date' => $summary->businessDate->toFormattedDateString(),
        ]);
    }
}
