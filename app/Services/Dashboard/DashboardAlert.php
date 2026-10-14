<?php

namespace App\Services\Dashboard;

use App\Data\Dashboard\DashboardAlertData;
use App\Enums\AuditStatus;
use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\Property;
use App\Services\NightAudit\AuditReadiness;
use App\Services\Property\PropertyToday;

/**
 * What is wrong with the night audit, if anything: the last audit failed, the audit waits for a blocker, or the
 * business date is more than a day behind the calendar. Nothing on a normal day. It reads only.
 * See docs/18-front-desk/04-dashboard.md#night-audit-alert.
 */
class DashboardAlert
{
    public function __construct(
        private AuditReadiness $readiness,
        private PropertyToday $propertyToday,
    ) {}

    public function forBusinessDate(Property $property, ?BusinessDate $businessDate): ?DashboardAlertData
    {
        if ($businessDate === null) {
            return null;
        }

        $day = $businessDate->date->format('j M');

        $failed = NightAudit::query()
            ->where('property_id', $property->id)
            ->where('business_date_id', $businessDate->id)
            ->where('status', AuditStatus::Failed->value)
            ->exists();

        if ($failed) {
            return new DashboardAlertData(__('Night audit for :date did not finish. The business date did not change.', ['date' => $day]));
        }

        if ($businessDate->status !== BusinessDateStatus::Open) {
            return null;
        }

        $blockers = $this->readiness->businessDateEnded($property) ? count($this->readiness->blockers($property)) : 0;

        if ($blockers > 0) {
            return new DashboardAlertData($blockers === 1
                ? __('Night audit for :date is waiting: 1 item needs a fix before it can run.', ['date' => $day])
                : __('Night audit for :date is waiting: :count items need a fix before it can run.', ['date' => $day, 'count' => $blockers]));
        }

        $behind = $businessDate->date->toImmutable()->startOfDay()->diffInDays($this->propertyToday->date($property), false);

        if ($behind > 1) {
            return new DashboardAlertData(__('The business date is :date. Run the night audit to catch up.', ['date' => $day]));
        }

        return null;
    }
}
