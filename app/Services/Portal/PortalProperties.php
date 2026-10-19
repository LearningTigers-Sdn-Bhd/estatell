<?php

namespace App\Services\Portal;

use App\Data\Portal\PortalInvitationData;
use App\Data\Portal\PortalPropertyData;
use App\Enums\CompanyUserStatus;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\CityLedger\CreditExposure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads what `/portal/properties` and the `/portal` invitations show a company user: their Active links with the open
 * balance and the arrivals of the next 7 days, and their Pending invitations. Four queries for the links and their relations, two for the balances (all
 * companies together, because the open balance does not depend on the property) and one for the arrivals (each
 * property's current business date comes from a join). The number of queries does not grow with the properties.
 */
class PortalProperties
{
    /**
     * Days after the business date that still count as upcoming.
     */
    public const int ARRIVAL_DAYS = 7;

    /**
     * Properties the dashboard lists before "View all".
     */
    public const int DASHBOARD_LIMIT = 5;

    public function __construct(private CreditExposure $creditExposure) {}

    /**
     * The Active links as cards, by property name.
     *
     * @return list<PortalPropertyData>
     */
    public function for(User $user): array
    {
        $active = $this->links($user)->where('status', CompanyUserStatus::Active)->values();
        $companyIds = $active->pluck('company_id')->all();
        $balances = $companyIds === [] ? [] : $this->creditExposure->openAr($companyIds);
        $arrivals = $companyIds === [] ? [] : $this->arrivals($companyIds);

        return $active
            ->sortBy(fn (CompanyUser $link): string => mb_strtolower($link->property->name))
            ->map(fn (CompanyUser $link): PortalPropertyData => PortalPropertyData::fromLink(
                $link,
                ...$this->state($link),
                openBalance: $balances[$link->company_id],
                arrivals: $arrivals[$link->company_id] ?? 0,
            ))
            ->values()
            ->all();
    }

    /**
     * The Pending invitations, for the dashboard.
     *
     * @return list<PortalInvitationData>
     */
    public function invitations(User $user): array
    {
        return $this->links($user)->where('status', CompanyUserStatus::Pending)
            ->map(fn (CompanyUser $link): PortalInvitationData => PortalInvitationData::fromLink($link))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, CompanyUser>
     */
    private function links(User $user): Collection
    {
        return CompanyUser::query()
            ->where('user_id', $user->id)
            ->open()
            ->with('company', 'property.settings')
            ->orderBy('id')
            ->get();
    }

    /**
     * The open balance of one company, for its property dashboard.
     */
    public function openBalance(int $companyId): string
    {
        return $this->creditExposure->openAr([$companyId])[$companyId];
    }

    /**
     * @return array{state: string, stateLabel: string}
     */
    private function state(CompanyUser $link): array
    {
        return match ($link->property->status) {
            PropertyStatus::Live => ['state' => 'open', 'stateLabel' => __('Open')],
            PropertyStatus::Suspended => ['state' => 'suspended', 'stateLabel' => __('Suspended')],
            default => ['state' => 'not_open_yet', 'stateLabel' => __('Not open yet')],
        };
    }

    /**
     * Confirmed reservations with a Confirmed unit arriving from the property's current business date up to
     * `ARRIVAL_DAYS` days later, by company id.
     *
     * @param  list<int>  $companyIds
     * @return array<int, int>
     */
    private function arrivals(array $companyIds): array
    {
        return DB::table('reservations')
            ->join('reservation_units', 'reservation_units.reservation_id', '=', 'reservations.id')
            ->join('business_dates', function ($join): void {
                $join->on('business_dates.property_id', '=', 'reservations.property_id')
                    ->where('business_dates.status', '<>', 'closed');
            })
            ->whereIn('reservations.company_id', $companyIds)
            ->where('reservations.status', ReservationStatus::Confirmed->value)
            ->where('reservation_units.status', UnitStatus::Confirmed->value)
            ->whereRaw('reservation_units.arrival_date >= business_dates.date')
            ->whereRaw('reservation_units.arrival_date <= business_dates.date + ?::int', [self::ARRIVAL_DAYS])
            ->groupBy('reservations.company_id')
            ->selectRaw('reservations.company_id, COUNT(DISTINCT reservations.id) AS total')
            ->pluck('total', 'company_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
