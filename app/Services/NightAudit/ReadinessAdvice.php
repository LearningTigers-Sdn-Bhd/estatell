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
    public function consequence(AuditCheck $check, Property $property): ?string
    {
        $settings = $property->settings;

        return match ($check) {
            AuditCheck::InHouseWithoutRoom => __('The audit cannot run until the unit has a room.'),
            AuditCheck::ArrivalNotCheckedIn => $settings->missed_arrival_policy === MissedArrivalPolicy::MarkNoShow
                ? __('If you leave it: marked no-show, and the no-show fee is charged.')
                : __('If you leave it: kept as a late arrival. Nothing is charged.'),
            AuditCheck::DepartureNotCheckedOut => $settings->overdue_departure_policy === OverdueDeparturePolicy::ExtendOneNight
                ? __('If you leave it: one more night is charged and the departure moves a day.')
                : __('If you leave it: no new night is charged. You finish it later.'),
            AuditCheck::FolioOpenAfterCheckOut => __('The audit does not touch it. Close it when the balance is settled.'),
            AuditCheck::CarriedException => null,
        };
    }

    /**
     * The reason an earlier audit left a unit, from the refusal code it stored.
     */
    public function reason(string $code): string
    {
        return match ($code) {
            'folio_not_open' => __('The folio was closed, so the night could not be charged.'),
            'nationality_required' => __('The guest has no nationality, and a tax needs it.'),
            'policy_not_found' => __('There is no no-show policy to charge.'),
            'charge_not_found' => __('A charge code is missing from the catalogue.'),
            'rate_missing' => __('There is no rate for the extra night.'),
            'not_available' => __('No room is free for the extra night.'),
            'restricted' => __('A rate restriction stops the extra night.'),
            'unit_not_postable' => __('The unit was not in a state that could be charged.'),
            ResolveOverdueDepartures::MESSAGE_BLOCKED => __('The guest is past the departure date and your policy blocks extending.'),
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

        $currency = $property->settings->currency->value;
        $total = Money::zero($currency);

        foreach (ReservationUnit::query()->whereKey($unitIds)->where('status', UnitStatus::Confirmed->value)->get() as $unit) {
            $total = $total->plus($this->policyChargeCalculator->calculate($policy, $unit)->amount);
        }

        return $total->isZero() ? null : $currency.' '.$total->getAmount();
    }
}
