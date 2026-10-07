<?php

namespace App\Actions\FrontDesk;

use App\Enums\ChargingUnit;
use App\Enums\SystemTransactionCode;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Catalogues\PolicyChargeCalculator;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ProcessEarlyDeparture
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PolicyChargeCalculator $policyChargeCalculator,
        private PostUnitPolicyFee $postUnitPolicyFee,
    ) {}

    /**
     * Shorten a unit's stay to `$newDeparture`: post the early departure policy fee, delete the unused nights and
     * their unposted add-on rows, and set `departure_date`. `ChangeUnitDates` calls this inside its transaction,
     * after its own checks. The unit row is locked by the caller. Every check here runs before the first write, so a
     * refusal writes nothing. The fee is read while the unit still holds its booked nights
     * (`PolicyChargeCalculator::calculate()` with the new departure). `$expectedFee` is the fee the form showed, and
     * `$doNotCharge` needs a `$reason`, as in `CancelUnit`.
     *
     * With `$applyPolicy` false the stay is cut with no fee and no checks on the policy. `ChangeUnitDates` passes false
     * when the arrival moves too, because that is a new booking of the unit, not an early departure. It then also
     * passes `$newArrival`, so both dates are saved in one write: a new departure alone can fall on or before the old
     * arrival and break the `departure_date > arrival_date` check.
     *
     * An add-on row goes when it is unposted and its date is on or after the new departure (nightly items) or after
     * it (other items, whose date may be the departure date). A posted add-on stays.
     * See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, CarbonInterface $newDeparture, bool $doNotCharge = false, ?string $reason = null, ?string $expectedFee = null, bool $applyPolicy = true, ?CarbonInterface $newArrival = null): StayDatesResult
    {
        return DB::transaction(function () use ($actor, $unit, $newDeparture, $doNotCharge, $reason, $expectedFee, $applyPolicy, $newArrival): StayDatesResult {
            $businessDate = $this->currentBusinessDate->date($unit->property);

            if ($businessDate === null) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $departure = $newDeparture->startOfDay();

            if ($departure->gte($unit->departure_date->startOfDay())) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_NOT_SHORTER);
            }

            $fee = '0.00';
            $postsFee = false;

            if ($applyPolicy) {
                $reason = filled($reason) ? trim((string) $reason) : null;

                if ($doNotCharge && $reason === null) {
                    return StayDatesResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
                }

                $policy = RoomRevenuePolicy::query()->where('property_id', $unit->property_id)->where('code', SystemTransactionCode::EarlyDepartureRevenue)->first();

                if ($policy === null) {
                    return StayDatesResult::refused(UnitStayResult::REFUSAL_POLICY_NOT_FOUND);
                }

                $charge = $this->policyChargeCalculator->calculate($policy, $unit, $departure);
                $fee = (string) $charge->amount->getAmount();
                $postsFee = ! $doNotCharge && ! $charge->isFree();

                if ($postsFee && $expectedFee !== null && bccomp($expectedFee, $fee, 2) !== 0) {
                    return StayDatesResult::refused(UnitStayResult::REFUSAL_FEE_CHANGED);
                }

                if ($postsFee) {
                    $posted = ($this->postUnitPolicyFee)($actor, $unit, $policy, $charge->amount, __('Early departure fee'), 'early_departure', $businessDate->toDateString());

                    if ($posted->isRefused()) {
                        return StayDatesResult::refused((string) $posted->refusal);
                    }
                }
            }

            $unit->nights()->whereDate('date', '>=', $departure->toDateString())->delete();
            $this->deleteUnpostedAddOns($unit, $departure);

            $unit->departure_date = $departure;

            if ($newArrival !== null) {
                $unit->arrival_date = $newArrival->startOfDay();
            }

            $unit->save();

            return StayDatesResult::done($unit, $fee, $postsFee);
        });
    }

    private function deleteUnpostedAddOns(ReservationUnit $unit, CarbonInterface $departure): void
    {
        $nightly = [ChargingUnit::PerNight->value, ChargingUnit::PerGuestNight->value];

        UnitAddOn::query()
            ->where('reservation_unit_id', $unit->id)
            ->whereNull('folio_line_id')
            ->whereNotNull('service_date')
            ->where(fn ($query) => $query
                ->where(fn ($nightlyRows) => $nightlyRows->whereDate('service_date', '>=', $departure->toDateString())->whereHas('chargeItem', fn ($item) => $item->whereIn('charging_unit', $nightly)))
                ->orWhereDate('service_date', '>', $departure->toDateString()))
            ->delete();
    }
}
