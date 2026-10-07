<?php

namespace App\Actions\NightAudit;

use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Models\NightAudit;
use App\Models\NightAuditException;

class RecordAuditException
{
    /**
     * Record that one unit could not be resolved by a step. The message is the refusal code of the Action that refused
     * it, or the error. A step run twice records one row for the same unit and type, never two.
     */
    public function __invoke(NightAudit $audit, AuditStep $step, AuditExceptionType $type, string $message, ?int $reservationUnitId = null): NightAuditException
    {
        $exception = $audit->exceptions()->firstOrNew([
            'step' => $step,
            'type' => $type,
            'reservation_unit_id' => $reservationUnitId,
        ]);
        $exception->property_id = $audit->property_id;
        $exception->message = $message;
        $exception->save();

        return $exception;
    }
}
