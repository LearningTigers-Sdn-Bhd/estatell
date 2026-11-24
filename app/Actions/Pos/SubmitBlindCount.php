<?php

namespace App\Actions\Pos;

use App\Enums\PosShiftStatus;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosShiftCashTotals;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The cashier counts the drawer without seeing the expected cash. The first count sets `recount_count` to 1. A count
 * that is off by more than the tolerance may be recounted once, if a manager approves (the approver has already been
 * checked for `pos.reports`): `recount_requires_approval`, then `recount_limit_reached` after the second count. A first
 * count that matches needs no recount (`recount_not_needed`). Both counts are logged. Only a shift that is ClosingControl
 * takes a count (`shift_not_closing`). A refusal writes nothing. See docs/34-native-pos/05-shifts-audit-and-einvoice.md.
 */
class SubmitBlindCount
{
    public function __construct(private PosShiftCashTotals $totals) {}

    public function __invoke(User $actor, Property $property, PosShift $shift, string $countedCash, ?User $recountApprover = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $shift, $countedCash, $recountApprover): PosActionResult {
            $shift = PosShift::query()->where('property_id', $property->id)->whereKey($shift->id)->lockForUpdate()->first();

            if ($shift === null) {
                return PosActionResult::refused('shift_invalid');
            }

            if ($shift->status !== PosShiftStatus::ClosingControl) {
                return PosActionResult::refused('shift_not_closing');
            }

            if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $countedCash) !== 1) {
                return PosActionResult::refused('counted_cash_invalid');
            }

            $isRecount = $shift->recount_count >= 1;

            if ($isRecount) {
                if ($shift->recount_count >= 2) {
                    return PosActionResult::refused('recount_limit_reached');
                }

                if ($recountApprover === null) {
                    return PosActionResult::refused('recount_requires_approval');
                }

                if (BigDecimal::of($shift->cash_variance)->abs()->isLessThanOrEqualTo(config('pos.cash_variance_tolerance'))) {
                    return PosActionResult::refused('recount_not_needed');
                }
            }

            $totals = $this->totals->for($shift);
            $counted = BigDecimal::of($countedCash)->toScale(2, RoundingMode::HalfUp);
            $variance = $counted->minus($totals['expected_cash'])->toScale(2, RoundingMode::HalfUp);

            $shift->forceFill($totals + [
                'counted_cash' => $counted->__toString(),
                'cash_variance' => $variance->__toString(),
                'recount_count' => $shift->recount_count + 1,
            ])->save();

            activity()->performedOn($shift)->causedBy($actor)->event($isRecount ? 'pos.shift_recounted' : 'pos.shift_counted')
                ->withProperties(['property_id' => $property->id, 'counted_cash' => $shift->counted_cash, 'cash_variance' => $shift->cash_variance, 'recount_count' => $shift->recount_count, 'approved_by_user_id' => $recountApprover?->id])
                ->log($isRecount ? 'POS shift recounted' : 'POS shift counted');

            return PosActionResult::saved($shift);
        });
    }
}
