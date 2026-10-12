<?php

namespace App\Actions\NightAudit;

use App\Actions\Folio\PostingResult;
use App\Models\NightAuditException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DismissAuditException
{
    public const REFUSAL_EXCEPTION_NOT_OPEN = 'exception_not_open';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    /**
     * Resolve an exception staff have handled another way, with the user and reason recorded, without posting.
     * Log on the unit's reservation, or on the exception itself when it has no unit. Refuse an already resolved
     * exception (`exception_not_open`) before a blank reason (`reason_required`). A refusal writes and logs nothing.
     * The exception row is locked first, so two clicks resolve and log it once.
     * See docs/20-night-audit/02-readiness-panel.md#dismiss-an-exception.
     */
    public function __invoke(User $actor, NightAuditException $exception, string $reason): PostingResult
    {
        return DB::transaction(function () use ($actor, $exception, $reason): PostingResult {
            $locked = NightAuditException::query()->where('property_id', $exception->property_id)->lockForUpdate()->findOrFail($exception->id);

            if ($locked->resolved_at !== null) {
                return PostingResult::refused(self::REFUSAL_EXCEPTION_NOT_OPEN);
            }

            $reason = trim($reason);

            if ($reason === '') {
                return PostingResult::refused(self::REFUSAL_REASON_REQUIRED);
            }

            $locked->forceFill(['resolved_at' => now(), 'resolved_by' => $actor->id])->save();
            activity()->performedOn($locked->reservationUnit?->reservation ?? $locked)->causedBy($actor)
                ->event('night_audit.exception_dismissed')
                ->withProperties(['property_id' => $locked->property_id, 'reservation_unit_id' => $locked->reservation_unit_id, 'night_audit_exception_id' => $locked->id, 'reason' => $reason])
                ->log('Night audit exception dismissed');

            return PostingResult::posted([]);
        });
    }
}
