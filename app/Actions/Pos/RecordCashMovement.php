<?php

namespace App\Actions\Pos;

use App\Enums\PosCashMovementKind;
use App\Enums\PosShiftStatus;
use App\Models\PosCashMovement;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Pos\OpenPosBusinessDate;
use App\Services\Pos\PosShiftCashTotals;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Record cash that went in or out of the drawer outside a sale: float in, safe drop, payout or a no-sale opening. Only a
 * shift that is Open takes movements (`shift_not_open`), so nothing changes while cash is counted. Float in, safe drop and
 * payout need an amount above zero and the counter transaction code the journal books them to (`counter_code_required`);
 * a no-sale opening needs an approver, who must already have been checked for `pos.void`, and moves no cash
 * (`approval_required`). The same idempotency key returns the earlier movement and writes nothing twice. A refusal writes nothing.
 * See docs/34-native-pos/05-shifts-audit-and-einvoice.md.
 */
class RecordCashMovement
{
    public function __construct(private OpenPosBusinessDate $openPosBusinessDate, private PosShiftCashTotals $totals) {}

    public function __invoke(User $actor, Property $property, PosShift $shift, PosCashMovementKind $kind, string $amount, string $reason, string $idempotencyKey, ?int $counterTransactionCodeId = null, ?User $approver = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $shift, $kind, $amount, $reason, $idempotencyKey, $counterTransactionCodeId, $approver): PosActionResult {
            $businessDate = $this->openPosBusinessDate->lock($property);

            if (is_string($businessDate)) {
                return PosActionResult::refused($businessDate);
            }

            $existing = PosCashMovement::query()->where('property_id', $property->id)->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return PosActionResult::replayed($existing);
            }

            $shift = PosShift::query()->where('property_id', $property->id)->whereKey($shift->id)->lockForUpdate()->first();

            if ($shift === null) {
                return PosActionResult::refused('shift_invalid');
            }

            if ($shift->status !== PosShiftStatus::Open) {
                return PosActionResult::refused('shift_not_open');
            }

            $isNoSale = $kind === PosCashMovementKind::NoSaleOpen;

            if (trim($reason) === '') {
                return PosActionResult::refused('reason_required');
            }

            if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount) !== 1 || (! $isNoSale && ! BigDecimal::of($amount)->isPositive())) {
                return PosActionResult::refused('amount_invalid');
            }

            if ($isNoSale && $approver === null) {
                return PosActionResult::refused('approval_required');
            }

            if (! $isNoSale && ($counterTransactionCodeId === null || ! TransactionCode::query()->where('property_id', $property->id)->whereKey($counterTransactionCodeId)->exists())) {
                return PosActionResult::refused('counter_code_required');
            }

            $movement = new PosCashMovement;
            $movement->property_id = $property->id;
            $movement->pos_shift_id = $shift->id;
            $movement->business_date = $businessDate->date;
            $movement->idempotency_key = $idempotencyKey;
            $movement->kind = $kind;
            $movement->counter_transaction_code_id = $isNoSale ? null : $counterTransactionCodeId;
            $movement->amount = $isNoSale ? '0.00' : BigDecimal::of($amount)->toScale(2)->__toString();
            $movement->reason = trim($reason);
            $movement->user_id = $actor->id;
            $movement->approved_by_user_id = $approver?->id;
            $movement->save();

            $shift->forceFill(array_intersect_key($this->totals->for($shift), array_flip(['paid_in', 'paid_out'])))->save();

            activity()->performedOn($movement)->causedBy($actor)->event('pos.cash_movement')
                ->withProperties(['property_id' => $property->id, 'pos_shift_id' => $shift->id, 'kind' => $kind->value, 'amount' => $movement->amount, 'approved_by_user_id' => $approver?->id])
                ->log('POS cash movement recorded');

            return PosActionResult::saved($movement);
        });
    }
}
