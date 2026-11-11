<?php

namespace App\Actions\Concierge;

use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\UnitStayRefused;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskStays;
use Illuminate\Support\Facades\DB;

class CheckOutConciergeStay
{
    public function __construct(private CheckOutUnit $checkOutUnit, private KioskStays $stays) {}

    /**
     * Check the guest out with the front desk's own `CheckOutUnit`, with no staff actor. The bill must be settled, which
     * `CheckOutUnit` enforces (`balance_not_settled`), and the check-out window must be open, the same window the kiosk
     * uses (`before_departure_date`). A stay already checked out answers done, so a retry is safe. `concierge.checked_out`
     * holds the property id only. Nothing is written for a refusal.
     */
    public function __invoke(ReservationUnit $stay): UnitStayResult
    {
        try {
            return DB::transaction(function () use ($stay): UnitStayResult {
                $locked = ReservationUnit::query()->where('property_id', $stay->property_id)->lockForUpdate()->findOrFail($stay->id);

                if ($locked->status === UnitStatus::CheckedOut) {
                    return UnitStayResult::done($locked);
                }

                if ($locked->status !== UnitStatus::CheckedIn) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
                }

                if (! $this->stays->checkOutWindow($locked)) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_BEFORE_DEPARTURE_DATE);
                }

                $result = ($this->checkOutUnit)(null, $locked);

                if ($result->isRefused()) {
                    throw new UnitStayRefused($result);
                }

                activity()
                    ->performedOn($locked)
                    ->event('concierge.checked_out')
                    ->withProperties(['property_id' => $locked->property_id])
                    ->log('Checked out on the concierge page');

                return $result;
            });
        } catch (UnitStayRefused $refused) {
            return $refused->result;
        }
    }
}
