<?php

namespace App\Services\BookingEngine;

use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Catalogues\CancellationPolicy;
use App\Services\Catalogues\PolicyChargeCalculator;
use App\Services\Catalogues\TaxCalculator;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Payments\CancelledUnitRefund;
use App\Services\Reservations\UnitNationality;
use Brick\Math\BigDecimal;

/**
 * Works out what a guest would pay and get back on cancelling their whole booking online. It closes on the arrival date,
 * by the business date, once a room has checked in, and when the fee with its tax is more than the money the room holds,
 * because a guest must not owe money after an online cancel. A room staff already cancelled is left out.
 * See docs/29-booking-engine/05-cancel-and-refund.md#cases.
 */
class OnlineCancellationQuotes
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private CancellationPolicy $cancellationPolicy,
        private PolicyChargeCalculator $policyChargeCalculator,
        private TaxCalculator $taxCalculator,
        private UnitNationality $unitNationality,
        private CancelledUnitRefund $cancelledUnitRefund,
    ) {}

    public function for(Reservation $reservation): OnlineCancellationQuote
    {
        $units = $reservation->units()->orderBy('id')->get();
        $confirmed = $units->filter(fn (ReservationUnit $unit): bool => $unit->status === UnitStatus::Confirmed);

        if ($units->contains(fn (ReservationUnit $unit): bool => in_array($unit->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true))) {
            return OnlineCancellationQuote::closed(OnlineCancellationQuote::REFUSAL_ALREADY_ARRIVED);
        }

        if ($confirmed->isEmpty()) {
            return OnlineCancellationQuote::closed(OnlineCancellationQuote::REFUSAL_ALREADY_CANCELLED);
        }

        $property = $reservation->property()->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);
        $policy = $this->cancellationPolicy->forReservation($reservation->property_id, $reservation->id);
        $code = $property->transactionCodes()->where('system_key', SystemTransactionCode::CancellationRevenue->value)->where('active', true)->first();

        if ($businessDate === null || $policy === null || $code === null) {
            return OnlineCancellationQuote::closed(OnlineCancellationQuote::REFUSAL_CONTACT_PROPERTY);
        }

        $paid = BigDecimal::zero();
        $feeTotal = BigDecimal::zero();
        $taxTotal = BigDecimal::zero();
        $refund = BigDecimal::zero();
        $fees = [];

        foreach ($confirmed as $unit) {
            /** @var ReservationUnit $unit */
            if ($unit->arrival_date->toImmutable()->startOfDay()->lessThanOrEqualTo($businessDate)) {
                return OnlineCancellationQuote::closed(OnlineCancellationQuote::REFUSAL_ALREADY_ARRIVED);
            }

            $charge = $this->policyChargeCalculator->calculate($policy, $unit);
            $tax = $this->taxCalculator->calculate($property, $code, $charge->amount, '1', $this->unitNationality->for($unit));

            if (! $tax->isCalculated()) {
                return OnlineCancellationQuote::closed(OnlineCancellationQuote::REFUSAL_CONTACT_PROPERTY);
            }

            $fee = BigDecimal::of((string) $charge->amount->getAmount());
            $gross = $charge->isFree() ? BigDecimal::zero() : BigDecimal::of((string) $tax->grossAmount->getAmount());
            $share = $this->cancelledUnitRefund->for($unit, $gross);

            if ($share->isShort) {
                return OnlineCancellationQuote::closed(OnlineCancellationQuote::REFUSAL_CONTACT_PROPERTY);
            }

            $fees[$unit->id] = (string) $fee->toScale(2);
            $paid = $paid->plus($share->held);
            $feeTotal = $feeTotal->plus($fee);
            $taxTotal = $taxTotal->plus($gross->minus($fee));
            $refund = $refund->plus($share->amount());
        }

        return OnlineCancellationQuote::open(
            (string) $paid->toScale(2),
            (string) $feeTotal->toScale(2),
            (string) $taxTotal->toScale(2),
            (string) $refund->toScale(2),
            $fees,
        );
    }
}
