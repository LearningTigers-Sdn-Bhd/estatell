<?php

namespace App\Jobs;

use App\Actions\NightAudit\PostRoomCharges;
use App\Actions\NightAudit\RecordAuditException;
use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Models\NightAudit;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

class PostRoomChargesJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public int $nightAuditId,
        public int $reservationUnitId,
        public string $date,
    ) {}

    /**
     * Post one night of one unit with a null actor. A refusal or an error becomes an exception row and never fails
     * the job, so the rest of the batch runs.
     */
    public function handle(PostRoomCharges $postRoomCharges, RecordAuditException $recordAuditException): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $audit = NightAudit::query()->find($this->nightAuditId);
        $unit = ReservationUnit::query()->find($this->reservationUnitId);

        if ($audit === null || $unit === null) {
            return;
        }

        try {
            $result = $postRoomCharges(null, $unit, CarbonImmutable::parse($this->date));

            if ($result->isRefused()) {
                $recordAuditException($audit, AuditStep::PostRoomCharges, AuditExceptionType::RoomCharge, (string) $result->refusal, $unit->id);
            }
        } catch (Throwable $exception) {
            report($exception);
            $recordAuditException($audit, AuditStep::PostRoomCharges, AuditExceptionType::UnexpectedError, $exception->getMessage(), $unit->id);
        }
    }
}
