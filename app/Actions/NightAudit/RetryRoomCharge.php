<?php

namespace App\Actions\NightAudit;

use App\Actions\Folio\PostingResult;
use App\Enums\AuditExceptionType;
use App\Models\NightAuditException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RetryRoomCharge
{
    public const REFUSAL_EXCEPTION_NOT_RETRIED = 'exception_not_retried';

    public function __construct(private PostRoomCharges $postRoomCharges) {}

    /**
     * Post the night an earlier audit could not charge: the unit's night on the business date of the exception's
     * audit, through `PostRoomCharges` with the user as actor, on the current business date. Posted, or already
     * posted, resolves the exception and logs it on the reservation. A refusal writes and logs nothing, so the
     * exception stays open. The exception row is locked first, so two clicks resolve it once.
     * See docs/20-night-audit/02-readiness-panel.md#retry-a-room-charge.
     */
    public function __invoke(User $actor, NightAuditException $exception): PostingResult
    {
        return DB::transaction(function () use ($actor, $exception): PostingResult {
            $locked = NightAuditException::query()->lockForUpdate()->findOrFail($exception->id);

            if ($locked->resolved_at !== null || $locked->type !== AuditExceptionType::RoomCharge || $locked->reservationUnit === null) {
                return PostingResult::refused(self::REFUSAL_EXCEPTION_NOT_RETRIED);
            }

            $night = CarbonImmutable::parse($locked->nightAudit->businessDate->date->toDateString());
            $result = ($this->postRoomCharges)($actor, $locked->reservationUnit, $night);

            if ($result->isRefused()) {
                return $result;
            }

            $locked->forceFill(['resolved_at' => now(), 'resolved_by' => $actor->id])->save();

            activity()
                ->performedOn($locked->reservationUnit->reservation)
                ->causedBy($actor)
                ->event('reservation.room_charge_retried')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->reservation_unit_id,
                    'night_audit_exception_id' => $locked->id,
                    'night' => $night->toDateString(),
                    'replayed' => $result->replayed,
                ])
                ->log('Room charge retried');

            return $result;
        });
    }
}
