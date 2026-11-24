<?php

namespace App\Services\Group;

use App\Data\Dashboard\DashboardTilesData;
use App\Data\Group\GroupDashboardData;
use App\Data\Group\GroupPropertyTilesData;
use App\Data\Group\GroupTotalsData;
use App\Data\Property\PropertySummaryData;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use App\Services\Dashboard\DashboardAlert;
use App\Services\Dashboard\DashboardTiles;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Staff\GroupProperties;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The group dashboard: each property's dashboard tiles on its own business date (`DashboardTiles`, the same figures
 * as the property dashboard), as the user's role there allows, and the totals across them. Its queries grow with the
 * number of properties shown, never with bookings. See docs/03-staff/05-group-mode.md.
 */
class GroupDashboard
{
    public function __construct(
        private GroupProperties $groupProperties,
        private DashboardTiles $tiles,
        private CurrentBusinessDate $businessDate,
        private DashboardAlert $alert,
    ) {}

    /**
     * @param  Collection<int, Property>  $properties  The properties the user may open in the group.
     */
    public function for(User $user, Collection $properties): GroupDashboardData
    {
        $permissions = $this->groupProperties->permissionsFor($user, $properties, [PermissionEnum::ReservationsView, PermissionEnum::ReportsView]);
        $blocks = [];

        foreach ($properties as $property) {
            $held = $permissions[$property->id] ?? [];
            $units = in_array(PermissionEnum::ReservationsView->value, $held, true);
            $reports = in_array(PermissionEnum::ReportsView->value, $held, true);
            $businessDate = $this->businessDate->get($property);
            $date = $businessDate === null ? null : CarbonImmutable::parse($businessDate->date->toDateString());

            $blocks[] = new GroupPropertyTilesData(
                property: PropertySummaryData::fromModel($property),
                businessDate: $date?->toDateString(),
                tiles: $date !== null && ($units || $reports) ? $this->tiles->forDate($property, $date, $units, $reports) : null,
                alert: $units || $reports ? $this->alert->forBusinessDate($property, $businessDate)?->message : null,
            );
        }

        return new GroupDashboardData($this->totals($blocks), $blocks);
    }

    /**
     * @param  list<GroupPropertyTilesData>  $blocks
     */
    private function totals(array $blocks): GroupTotalsData
    {
        $tiles = array_values(array_filter(array_map(fn (GroupPropertyTilesData $block): ?DashboardTilesData => $block->tiles, $blocks)));
        $sum = fn (callable $pick): ?int => ($values = array_values(array_filter(array_map($pick, $tiles), fn ($value): bool => $value !== null))) === [] ? null : (int) array_sum($values);
        $sold = $sum(fn (DashboardTilesData $tile): ?int => $tile->tonight?->nights[0]->sold);
        $onSale = $sum(fn (DashboardTilesData $tile): ?int => $tile->tonight?->nights[0]->total);
        $months = array_values(array_filter(array_map(fn (DashboardTilesData $tile) => $tile->month, $tiles)));
        $currencies = array_unique(array_map(fn ($month): ?string => $month->currency?->code, $months));
        $oneCurrency = $months !== [] && count($currencies) === 1;
        $money = fn (callable $pick): string => (string) array_reduce($months, fn (BigDecimal $total, $month): BigDecimal => $total->plus($pick($month)), BigDecimal::zero())->toScale(2);

        return new GroupTotalsData(
            arrivalsRemaining: $sum(fn (DashboardTilesData $tile): ?int => $tile->arrivals?->remaining),
            arrivalsTotal: $sum(fn (DashboardTilesData $tile): ?int => $tile->arrivals?->total),
            departuresRemaining: $sum(fn (DashboardTilesData $tile): ?int => $tile->departures?->remaining),
            departuresTotal: $sum(fn (DashboardTilesData $tile): ?int => $tile->departures?->total),
            occupancy: $sold === null || ! $onSale ? null : min(100, (int) round($sold / $onSale * 100)),
            unitsSold: $sold,
            unitsOnSale: $onSale,
            newBookings: $sum(fn (DashboardTilesData $tile): ?int => $tile->newBookings?->bookings),
            newRooms: $sum(fn (DashboardTilesData $tile): ?int => $tile->newBookings?->rooms),
            monthPosted: $oneCurrency ? $money(fn ($month): string => $month->posted) : null,
            monthBookedAmount: $oneCurrency ? $money(fn ($month): string => $month->bookedAmount) : null,
            currency: $oneCurrency ? $months[0]->currency : null,
            revenueProperties: count($months),
            properties: count($blocks),
        );
    }
}
