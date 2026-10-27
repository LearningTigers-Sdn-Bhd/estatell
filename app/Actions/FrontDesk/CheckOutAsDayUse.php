<?php

namespace App\Actions\FrontDesk;

use App\Actions\Folio\PostDayUseBlock;
use App\Data\Reservations\CheckOutCollectData;
use App\Data\Reservations\CheckOutDamageData;
use App\Enums\StayType;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\CheckOutAsDayUseQuote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckOutAsDayUse
{
    public function __construct(
        private CheckOutAsDayUseQuote $quote,
        private PostDayUseBlock $postDayUseBlock,
        private CheckOutAndRefund $checkOutAndRefund,
    ) {}

    public function __invoke(User $actor, ReservationUnit $unit, ?string $start = null, ?string $end = null, ?string $amount = null, ?string $reason = null, ?string $refundAmount = null, ?CheckOutCollectData $collect = null, ?string $idempotencyKey = null, ?CheckOutDamageData $damage = null): UnitStayResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $unit, $start, $end, $amount, $reason, $refundAmount, $collect, $idempotencyKey, $damage);
        } catch (Throwable $exception) {
            DB::rollBack();

            if ($exception instanceof QueryException && $exception->getCode() === '23P01') {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NOT_AVAILABLE);
            }

            throw $exception;
        }

        $result->isRefused() ? DB::rollBack() : DB::commit();

        return $result;
    }

    private function run(User $actor, ReservationUnit $unit, ?string $start, ?string $end, ?string $amount, ?string $reason, ?string $refundAmount, ?CheckOutCollectData $collect, ?string $idempotencyKey, ?CheckOutDamageData $damage): UnitStayResult
    {
        $locked = ReservationUnit::query()->where('property_id', $unit->property_id)->lockForUpdate()->findOrFail($unit->id);
        $quote = $this->quote->quote($locked, $start, $end, $amount, $reason);

        if ($quote->refusal !== null) {
            return UnitStayResult::refused($quote->refusal);
        }

        $night = $locked->nights()->whereDate('date', $quote->date)->first();
        $roomId = $night->room_id ?? $night?->bed?->room_id;
        $oldDates = ['arrival_date' => $locked->arrival_date->toDateString(), 'departure_date' => $locked->departure_date->toDateString()];
        $locked->nights()->delete();
        $locked->forceFill([
            'stay_type' => StayType::DayUse,
            'departure_date' => $locked->arrival_date,
            'arrival_time' => $quote->start,
            'departure_time' => $quote->blockEnd,
            'rate_plan_id' => $quote->ratePlanId ?? $locked->rate_plan_id,
        ])->save();
        $locked->dayUse()->create([
            'date' => $quote->date,
            'room_id' => $roomId,
            'starts_at' => $quote->start,
            'ends_at' => $quote->blockEnd,
            'hours' => $quote->hours,
            'amount' => $quote->amount,
            'override_amount' => $quote->overrideAmount,
        ]);
        $posted = ($this->postDayUseBlock)($actor, $locked, $quote->date);

        if ($posted->isRefused()) {
            return UnitStayResult::refused((string) $posted->refusal);
        }

        $result = ($this->checkOutAndRefund)($actor, $locked, $refundAmount, $collect, $idempotencyKey, $damage);

        if ($result->isRefused()) {
            return $result;
        }

        activity()->performedOn($locked->reservation)->causedBy($actor)
            ->event('reservation.unit_checked_out_as_day_use')
            ->withProperties([
                'property_id' => $locked->property_id,
                'reservation_unit_id' => $locked->id,
                'business_date' => $quote->date,
                'old' => $oldDates,
                'room_id' => $roomId,
                'starts_at' => $quote->start,
                'ends_at' => $quote->blockEnd,
                'hours' => $quote->hours,
                'rate_plan_id' => $locked->rate_plan_id,
                'amount' => $quote->amount,
                'override_amount' => $quote->overrideAmount,
                'reason' => filled($reason) ? trim($reason) : null,
            ])->log('Unit checked out as day use');

        return $result;
    }
}
