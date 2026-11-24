<?php

namespace App\Actions\Pos;

use App\Enums\PosShiftStatus;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\OpenPosBusinessDate;
use App\Services\Pos\PosShiftCashTotals;
use App\Services\Pos\PosZReport;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Close a counted shift: stamp the Open business date the cash variance books on (`closed_business_date`), fix the
 * totals, freeze the Z-report and move to Closed. A shift that spans the 2 AM Night Audit closes onto the new date, so
 * the variance never lands on a date that is already closed. Needs a count first (`count_required`). Uses the Open date
 * and so waits out the audit (`business_date_auditing`). A refusal writes nothing.
 */
class FinalizeCloseShift
{
    public function __construct(private OpenPosBusinessDate $openPosBusinessDate, private PosShiftCashTotals $totals, private PosZReport $zReport) {}

    public function __invoke(User $actor, Property $property, PosShift $shift, ?string $note = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $shift, $note): PosActionResult {
            $businessDate = $this->openPosBusinessDate->lock($property);

            if (is_string($businessDate)) {
                return PosActionResult::refused($businessDate);
            }

            $shift = PosShift::query()->where('property_id', $property->id)->whereKey($shift->id)->lockForUpdate()->first();

            if ($shift === null) {
                return PosActionResult::refused('shift_invalid');
            }

            if ($shift->status !== PosShiftStatus::ClosingControl) {
                return PosActionResult::refused('shift_not_closing');
            }

            if ($shift->counted_cash === null) {
                return PosActionResult::refused('count_required');
            }

            $totals = $this->totals->for($shift);
            $variance = BigDecimal::of($shift->counted_cash)->minus($totals['expected_cash'])->toScale(2);

            $shift->forceFill($totals + [
                'cash_variance' => $variance->__toString(),
                'status' => PosShiftStatus::Closed,
                'closed_business_date' => $businessDate->date,
                'closed_by_user_id' => $actor->id,
                'closed_at' => now(),
                'closing_note' => $note === null || trim($note) === '' ? null : trim($note),
            ])->save();

            $shift->forceFill(['z_report_snapshot' => $this->zReport->build($shift)])->save();

            activity()->performedOn($shift)->causedBy($actor)->event('pos.shift_closed')
                ->withProperties(['property_id' => $property->id, 'closed_business_date' => $businessDate->date->toDateString(), 'cash_variance' => $shift->cash_variance])
                ->log('POS shift closed');

            return PosActionResult::saved($shift);
        });
    }
}
