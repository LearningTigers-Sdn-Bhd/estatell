<?php

namespace App\Actions\Reservations;

use App\Actions\Folio\FollowCompanyOnMasterFolio;
use App\Actions\Folio\SyncRoutingRules;
use App\Enums\CompanyStatus;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateReservationCompany
{
    public function __construct(private FollowCompanyOnMasterFolio $followCompany, private SyncRoutingRules $syncRoutingRules) {}

    /**
     * Set or clear the billed company. Refused when the reservation is closed (`reservation_closed`), when the company
     * is not an Active company of the property (`company_not_available`), or while the Master folio holds lines
     * (`master_has_lines`), or while a live unit uses a plan the new company cannot book (`company_plan_conflict`). The same company is not a change: nothing is written or logged, and a Suspended company a
     * reservation already holds can stay. An empty Master folio's payer follows, and `SyncRoutingRules` runs so a new
     * company gets its Master folio. See docs/15-city-ledger/01-companies.md.
     */
    public function __invoke(User $actor, Reservation $reservation, ?int $companyId): ReservationResult
    {
        return DB::transaction(function () use ($actor, $reservation, $companyId): ReservationResult {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if (in_array($locked->status, [ReservationStatus::Cancelled, ReservationStatus::Completed], true)) {
                return ReservationResult::refused(ReservationResult::REFUSAL_RESERVATION_CLOSED);
            }

            if ($locked->company_id === $companyId) {
                return ReservationResult::created($reservation);
            }

            if ($companyId !== null && ! Company::query()->where('property_id', $locked->property_id)->where('status', CompanyStatus::Active)->whereKey($companyId)->exists()) {
                return ReservationResult::refused(ReservationResult::REFUSAL_COMPANY_NOT_AVAILABLE);
            }

            $usesPlanOfOldCompany = $locked->units()
                ->whereNotIn('status', [UnitStatus::Cancelled, UnitStatus::NoShow])
                ->whereHas('ratePlan', fn ($plan) => $plan->whereHas('companies')->when(
                    $companyId !== null,
                    fn ($plans) => $plans->whereDoesntHave('companies', fn ($companies) => $companies->whereKey($companyId)),
                ))
                ->exists();

            if ($usesPlanOfOldCompany) {
                return ReservationResult::refused(ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT);
            }

            if (! ($this->followCompany)($locked, $companyId)) {
                return ReservationResult::refused(ReservationResult::REFUSAL_MASTER_HAS_LINES);
            }

            $oldCompanyId = $locked->company_id;
            $locked->company_id = $companyId;
            $locked->save();

            ($this->syncRoutingRules)($locked);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('reservation.company_updated')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'old' => ['company_id' => $oldCompanyId],
                    'new' => ['company_id' => $companyId],
                ])
                ->log('Reservation company updated');

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return ReservationResult::created($reservation);
        });
    }
}
