<?php

namespace App\Actions\NightAudit;

use App\Enums\AuditStatus;
use App\Enums\NotificationType;
use App\Models\NightAudit;
use App\Models\User;
use App\Notifications\NightAuditCompleted;
use App\Notifications\NightAuditExceptions;
use App\Notifications\NightAuditFailed;
use App\Notifications\NightAuditResultNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class NotifyNightAuditResult
{
    /**
     * Tell the property's staff how a night audit ended: `NightAuditFailed` for a failed audit, `NightAuditExceptions`
     * for a completed audit with an open exception, and `NightAuditCompleted` otherwise. A Running audit sends nothing.
     * The recipients are the active staff of the property who hold the permission of the type, and the notification
     * applies the property and user preferences. Returns the type sent, or null when nothing was sent.
     * See docs/20-night-audit/05-statistics.md.
     */
    public function __invoke(NightAudit $audit): ?NotificationType
    {
        $notification = match ($audit->status) {
            AuditStatus::Failed => new NightAuditFailed($audit),
            AuditStatus::Completed => $audit->exceptions()->open()->exists() ? new NightAuditExceptions($audit) : new NightAuditCompleted($audit),
            AuditStatus::Running => null,
        };

        if ($notification === null) {
            return null;
        }

        Notification::send($this->recipients($audit, $notification), $notification);

        return $notification->type;
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(NightAudit $audit, NightAuditResultNotification $notification): Collection
    {
        $permission = $notification->type->recipientPermission();
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($audit->property_id);

        try {
            return User::query()
                ->where('property_id', $audit->property_id)
                ->where('is_admin', false)
                ->whereNull('deactivated_at')
                ->permission((string) $permission)
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
