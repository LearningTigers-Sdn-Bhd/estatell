<?php

namespace App\Actions\Pos;

use App\Enums\DocumentType;
use App\Enums\PosCashMovementKind;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Features\NativePos;
use App\Models\PosCashMovement;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\NextDocumentNumber;
use App\Services\Pos\OpenPosBusinessDate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Open a register shift on the Open business date. The opening cash defaults to what the register's last shift counted.
 * When the cashier starts with a different float, the difference is booked on that last shift as a safe drop or a float
 * in against the outlet's safe clearing code, so no cash goes unaccounted between shifts (`safe_code_required` when the
 * outlet has none). Refuses an outlet with no cash payment method (`cash_payment_method_required`). A register trades through one shift at a time (`shift_already_open`). Refuses an outlet that is not
 * native or has no cash variance code, and no rounding code while rounding is on, so the daily journal can always
 * balance. The shift number comes from the `pos_shift` document sequence just before the write. A refusal writes nothing.
 * See docs/34-native-pos/05-shifts-audit-and-einvoice.md.
 */
class OpenPosShift
{
    public function __construct(private OpenPosBusinessDate $openPosBusinessDate, private NextDocumentNumber $nextDocumentNumber) {}

    public function __invoke(User $actor, Property $property, PosRegister $register, ?string $openingCash = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $register, $openingCash): PosActionResult {
            $businessDate = $this->openPosBusinessDate->lock($property);

            if (is_string($businessDate)) {
                return PosActionResult::refused($businessDate);
            }

            $register = PosRegister::query()->where('property_id', $property->id)->whereKey($register->id)->lockForUpdate()->first();
            $outlet = $register?->outlet;

            if ($register === null || ! $register->active || $outlet === null || ! $outlet->active || ! $outlet->native_pos_enabled) {
                return PosActionResult::refused('register_invalid');
            }

            if (! Feature::for($property)->active(NativePos::class)) {
                return PosActionResult::refused('native_pos_disabled');
            }

            if ($outlet->cash_variance_transaction_code_id === null) {
                return PosActionResult::refused('cash_variance_code_required');
            }

            if ($outlet->rounding_mode->value !== 'none' && $outlet->rounding_transaction_code_id === null) {
                return PosActionResult::refused('rounding_code_required');
            }

            if (! $outlet->paymentMethods()->where('tender_type', PosTenderType::Cash->value)->where('active', true)->exists()) {
                return PosActionResult::refused('cash_payment_method_required');
            }

            if (PosShift::query()->where('pos_register_id', $register->id)->where('status', '!=', PosShiftStatus::Closed->value)->exists()) {
                return PosActionResult::refused('shift_already_open');
            }

            $previous = PosShift::query()->where('pos_register_id', $register->id)->where('status', PosShiftStatus::Closed->value)->orderByDesc('id')->first();
            $opening = $openingCash ?? $previous->counted_cash ?? '0.00';

            if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $opening) !== 1) {
                return PosActionResult::refused('opening_cash_invalid');
            }

            $opening = BigDecimal::of($opening)->toScale(2, RoundingMode::HalfUp);
            $handover = $previous?->counted_cash === null ? BigDecimal::zero() : BigDecimal::of($previous->counted_cash)->minus($opening);

            if (! $handover->isZero() && $outlet->safe_clearing_transaction_code_id === null) {
                return PosActionResult::refused('safe_code_required');
            }

            $shift = new PosShift(['opening_cash' => $opening->__toString()]);
            $shift->property_id = $property->id;
            $shift->pos_outlet_id = $outlet->id;
            $shift->pos_register_id = $register->id;
            $shift->opened_business_date = $businessDate->date;
            $shift->shift_number = $this->nextDocumentNumber->__invoke($property, DocumentType::PosShift)->reference;
            $shift->status = PosShiftStatus::Open;
            $shift->opened_by_user_id = $actor->id;
            $shift->opened_at = now();
            $shift->save();

            if (! $handover->isZero()) {
                $movement = new PosCashMovement;
                $movement->property_id = $property->id;
                $movement->pos_shift_id = $previous->id;
                $movement->business_date = $businessDate->date;
                $movement->idempotency_key = 'handover:'.$shift->id;
                $movement->kind = $handover->isPositive() ? PosCashMovementKind::SafeDrop : PosCashMovementKind::FloatIn;
                $movement->counter_transaction_code_id = $outlet->safe_clearing_transaction_code_id;
                $movement->amount = $handover->abs()->toScale(2)->__toString();
                $movement->reason = 'Float adjusted at shift handover';
                $movement->user_id = $actor->id;
                $movement->save();
            }

            activity()->performedOn($shift)->causedBy($actor)->event('pos.shift_opened')
                ->withProperties(['property_id' => $property->id, 'pos_register_id' => $register->id, 'opening_cash' => $shift->opening_cash, 'handover' => $handover->toScale(2)->__toString()])
                ->log('POS shift opened');

            return PosActionResult::saved($shift);
        });
    }
}
