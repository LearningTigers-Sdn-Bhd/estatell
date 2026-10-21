<?php

namespace App\Actions\FrontDesk;

use App\Data\Reservations\CheckOutCollectData;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\PendingNightQuote;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckOutOverdueStay
{
    public const RESTRICTION_REASON = 'Guest stayed the night';

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PendingNightQuote $pendingNightQuote,
        private ExtendStay $extendStay,
        private CheckOutAndRefund $checkOutAndRefund,
    ) {}

    /**
     * They stayed last night: a CheckedIn unit that was due to leave on the business date while the night audit is
     * pending gets that night. `ExtendStay` moves the departure to the calendar date (restrictions pass with the fixed
     * reason "Guest stayed the night", because the guest already slept there, and `not_available` is still refused), then
     * `CheckOutAndRefund` collects, refunds and checks out, and `PostPendingNight` inside it posts the new night and its
     * add-ons with the audit's keys. One transaction: any refusal writes nothing. A unit that is not an Overdue guest is
     * refused with `not_overdue`. They left yesterday is the normal check-out. Permissions (`front-desk.check-out` and
     * `reservations.manage`) are checked by the route and the Form Request.
     * See docs/18-front-desk/01-check-in-and-check-out.md#night-audit-pending.
     */
    public function __invoke(User $actor, ReservationUnit $unit, ?string $refundAmount = null, ?CheckOutCollectData $collect = null, ?string $idempotencyKey = null): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $unit, $refundAmount, $collect, $idempotencyKey);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function run(User $actor, ReservationUnit $unit, ?string $refundAmount, ?CheckOutCollectData $collect, ?string $idempotencyKey): UnitStayResult
    {
        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

        if ($locked->status !== UnitStatus::CheckedIn) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
        }

        $businessDate = $this->currentBusinessDate->date($locked->property);

        if ($businessDate === null) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
        }

        if (! $this->pendingNightQuote->isOverdue($locked, $businessDate)) {
            return UnitStayResult::refused(UnitStayResult::REFUSAL_NOT_OVERDUE);
        }

        $extended = ($this->extendStay)($actor, $locked, $businessDate->addDay(), self::RESTRICTION_REASON);

        if ($extended->isRefused()) {
            return UnitStayResult::refused((string) $extended->refusal, $extended->refusal === UnitStayResult::REFUSAL_NOT_AVAILABLE ? [$this->roomLabel($locked), $businessDate->format('j M')] : []);
        }

        return ($this->checkOutAndRefund)($actor, $locked->refresh(), $refundAmount, $collect, $idempotencyKey);
    }

    /**
     * The room of the unit's last night, or its room type name when it has none.
     */
    private function roomLabel(ReservationUnit $unit): string
    {
        $night = $unit->nights()->with('room', 'bed.room')->where(fn ($nights) => $nights->whereNotNull('room_id')->orWhereNotNull('bed_id'))->orderByDesc('date')->first();

        return $night?->room->number ?? $night?->bed?->room->number ?? $unit->roomType()->value('name');
    }
}
