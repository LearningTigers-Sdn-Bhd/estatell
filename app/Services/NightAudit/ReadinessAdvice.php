<?php

namespace App\Services\NightAudit;

use App\Actions\NightAudit\Steps\ResolveOverdueDepartures;
use App\Enums\AuditCheck;
use App\Enums\MissedArrivalPolicy;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Services\Catalogues\PolicyChargeCalculator;
use Brick\Money\Money;

/**
 * The plain words of the night audit sheet: what the audit does with a row if nobody touches it, why an earlier audit
 * left a unit, and what the no-show fee would be. The consequences read the property's own policies, so they say
 * what the audit will really do. It writes nothing.
 */
class ReadinessAdvice
{
    public function __construct(private PolicyChargeCalculator $policyChargeCalculator) {}

    /**
     * What the audit does with a row of this check when nobody fixes it, or null when it does nothing to it.
     */
    public function consequence(AuditCheck $check, Property $property, bool $dayUse = false): ?string
    {
        $settings = $property->settings;

        return match ($check) {
            AuditCheck::InHouseWithoutRoom => __('The audit cannot run until the unit has a room.'),
            AuditCheck::ArrivalNotCheckedIn => $settings->missed_arrival_policy === MissedArrivalPolicy::MarkNoShow
                ? __('If you leave it: marked no-show, and the no-show fee is charged.')
                : __('If you leave it: kept as a late arrival. Nothing is charged.'),
            AuditCheck::DepartureNotCheckedOut => $dayUse
                ? __('If you leave it: nothing is charged and the unit stays checked in. A day use is never extended. Check the guest out.')
                : ($settings->overdue_departure_policy === OverdueDeparturePolicy::ExtendOneNight
                ? __('If you leave it: one more night is charged and the departure moves a day.')
                : __('If you leave it: no new night is charged. You finish it later.')),
            AuditCheck::FolioOpenAfterCheckOut => __('The audit does not touch it. Close it when the balance is settled.'),
            AuditCheck::MasterNotBilled => __('The audit does not touch it. Settle the folio to the city ledger, or collect the balance and close it.'),
            AuditCheck::MoneyHeldOnClosedBooking => __('The audit does not touch it. Refund the money, or post the charge it covers.'),
            AuditCheck::CarriedException => null,
        };
    }

    /**
     * The reason an earlier audit left a unit, from the refusal code it stored.
     */
    public function reason(string $code, ?Money $remaining = null): string
    {
        return match ($code) {
            'exception_not_retried', 'exception_not_open' => __('There is nothing left to fix here. The list has been refreshed.'),
            'not_package_night' => __('This exception is not a package night whose price needs fixing.'),
            'reason_required' => __('Enter a reason to dismiss this exception.'),
            'change_price_required' => __('Correcting charged nights needs the Change price permission.'),
            'package_below_inclusions' => $remaining === null
                ? __('The package price left cannot cover these nights\' inclusions. Change the price with Change price.')
                : __('The package price left (:amount) cannot cover these nights\' inclusions. Change the price with Change price.', ['amount' => $remaining->getCurrency()->getCurrencyCode().' '.number_format($remaining->getAmount()->toFloat(), 2)]),
            'folio_not_open' => __('The folio was closed, so the night could not be charged.'),
            'folio_settled_to_city_ledger' => __('The folio was settled to the city ledger, so the charge could not be posted. Open a third-party folio for the company and route it there.'),
            'nationality_required' => __('The guest has no nationality, and a tax needs it.'),
            'policy_not_found' => __('There is no no-show policy to charge.'),
            'charge_not_found' => __('A charge code is missing from the catalogue.'),
            'rate_missing' => __('There is no rate for the extra night.'),
            'not_available' => __('No room is free for the extra night.'),
            'restricted' => __('A rate restriction stops the extra night.'),
            'unit_not_postable' => __('The unit was not in a state that could be charged.'),
            'exceeds_night_amount' => __('The night\'s price is less than its inclusions, so it could not be split.'),
            ResolveOverdueDepartures::MESSAGE_BLOCKED => __('The guest is past the departure date and your policy blocks extending.'),
            ResolveOverdueDepartures::MESSAGE_DAY_USE => __('A day use is never extended. Check the guest out.'),
            ResolveOverdueDepartures::MESSAGE_PAST_DEPARTURE => __('The guest is more than a day past the departure date.'),
            default => __('The audit could not finish this unit (:code).', ['code' => $code]),
        };
    }

    /**
     * The no-show fee of the listed units together, as `MYR 60.00`, or null when the policy charges nothing or is missing.
     *
     * @param  list<int>  $unitIds
     */
    public function noShowFee(Property $property, array $unitIds): ?string
    {
        $policy = RoomRevenuePolicy::query()->where('property_id', $property->id)->where('code', SystemTransactionCode::NoShowRevenue)->first();

        if ($policy === null) {
            return null;
        }

        $currency = $property->settings->currency->getCurrencyCode();
        $total = Money::zero($currency);

        foreach (ReservationUnit::query()->whereKey($unitIds)->where('status', UnitStatus::Confirmed->value)->get() as $unit) {
            $total = $total->plus($this->policyChargeCalculator->calculate($policy, $unit)->amount);
        }

        return $total->isZero() ? null : $currency.' '.$total->getAmount();
    }
}
