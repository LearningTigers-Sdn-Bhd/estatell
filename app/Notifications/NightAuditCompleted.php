<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\NightAudit;
use App\Services\NightAudit\AuditSummary;

/**
 * The night audit completed with no open exception.
 */
class NightAuditCompleted extends NightAuditResultNotification
{
    public function __construct(NightAudit $audit)
    {
        parent::__construct($audit, NotificationType::NightAuditCompleted);
    }

    protected function subject(AuditSummary $summary): string
    {
        return __('Night audit completed for :date', ['date' => $summary->businessDate->toFormattedDateString()]);
    }
}
