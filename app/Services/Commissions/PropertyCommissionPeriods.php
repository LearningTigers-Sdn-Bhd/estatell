<?php

namespace App\Services\Commissions;

use App\Data\Commissions\PropertyCommissionPeriodData;
use App\Data\Commissions\PropertyCommissionServiceData;
use App\Enums\CommissionService;
use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Reads the periods each property is charged commission for a service. A booking is charged when its check-out date (the
 * latest unit departure date) falls inside a period, end date included. No period means no commission.
 * See docs/30-commission-fees/02-commission-rules.md#charging-periods.
 */
class PropertyCommissionPeriods
{
    public function __construct(
        private CommissionRates $commissionRates,
        private PayoutCycle $payoutCycle,
    ) {}

    /**
     * The date a booking's commission is judged on. It does not move when the booking is checked again later, so a period
     * that starts after a booking ended never charges it.
     */
    public function chargeDate(Reservation $reservation): CarbonImmutable
    {
        /** @var ReservationUnit $last */
        $last = $reservation->units->sortByDesc(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString())->first();

        return $last->departure_date;
    }

    public function covers(int $propertyId, CommissionService $service, CarbonImmutable $date): bool
    {
        return PropertyCommissionPeriod::query()
            ->where('property_id', $propertyId)
            ->where('service', $service)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date->toDateString()))
            ->exists();
    }

    /**
     * Each service with today's rate and its running or next period.
     *
     * @return list<PropertyCommissionServiceData>
     */
    public function servicesFor(Property $property): array
    {
        $today = $this->payoutCycle->today();
        $periods = $this->periodsOf($property);

        return array_map(function (CommissionService $service) use ($periods, $today): PropertyCommissionServiceData {
            $period = $periods
                ->filter(fn (PropertyCommissionPeriod $period): bool => $period->service === $service && ($period->ends_on === null || $period->ends_on->toDateString() >= $today->toDateString()))
                ->sortBy(fn (PropertyCommissionPeriod $period): string => $period->starts_on->toDateString())
                ->first();

            return new PropertyCommissionServiceData(
                service: $service,
                label: $service->label(),
                rate: $this->commissionRates->rateOn($service, $today),
                period: $period === null ? null : PropertyCommissionPeriodData::fromModel($period, $today),
            );
        }, CommissionService::cases());
    }

    /**
     * Every period of the property, newest first.
     *
     * @return list<PropertyCommissionPeriodData>
     */
    public function historyFor(Property $property): array
    {
        $today = $this->payoutCycle->today();

        return array_values($this->periodsOf($property)
            ->map(fn (PropertyCommissionPeriod $period): PropertyCommissionPeriodData => PropertyCommissionPeriodData::fromModel($period, $today))
            ->all());
    }

    /**
     * @return EloquentCollection<int, PropertyCommissionPeriod>
     */
    private function periodsOf(Property $property): EloquentCollection
    {
        return $property->commissionPeriods()
            ->with('creator:id,name')
            ->orderByDesc('starts_on')
            ->get();
    }
}
