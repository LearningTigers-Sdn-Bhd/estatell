<?php

namespace App\Actions\Reservations;

use App\Actions\CityLedger\RecordCreditOverride;
use App\Actions\Folio\FollowCompanyOnMasterFolio;
use App\Actions\Folio\SyncRoutingRules;
use App\Enums\CompanyStatus;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CityLedger\CreditCheck;
use App\Services\Reservations\StayTotal;
use Illuminate\Support\Facades\DB;

class UpdateReservationCompany
{
    public function __construct(private FollowCompanyOnMasterFolio $followCompany,
        private SyncRoutingRules $syncRoutingRules,
        private CreditCheck $creditCheck,
        private RecordCreditOverride $recordCreditOverride,
        private StayTotal $stayTotal,
    ) {}

    /**
     * Set or clear the billed company. Refused when the reservation is closed (`reservation_closed`), when the company
     * is not an Active company of the property (`company_not_available`), or while the Master folio holds
     * money (`master_has_payments`) or has an AR invoice that is not Void (`master_has_ar_invoice`), or while a live unit uses a plan the new company cannot book (`company_plan_conflict`), or when the stay total would take the company over its credit limit without an override reason (`credit_limit_exceeded`). The same company is not a change: nothing is written or logged, and a Suspended company a
     * reservation already holds can stay. The Master folio's payer follows with its charges, and `SyncRoutingRules` runs so a new
     * company gets its Master folio. See docs/15-city-ledger/01-companies.md.
     */
    public function __invoke(User $actor, Reservation $reservation, ?int $companyId, ?string $overrideReason = null): ReservationResult
    {
        return DB::transaction(function () use ($actor, $reservation, $companyId, $overrideReason): ReservationResult {
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

            $check = null;
            $company = $companyId === null ? null : Company::query()->find($companyId);

            if ($company !== null) {
                $check = $this->creditCheck->check($company, $this->stayTotal->forReservation($locked));

                if (! $check->allowed && trim((string) $overrideReason) === '') {
                    return ReservationResult::refused(ReservationResult::REFUSAL_CREDIT_LIMIT_EXCEEDED);
                }
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

            $refusal = ($this->followCompany)($locked, $companyId);

            if ($refusal !== null) {
                return $refusal;
            }

            if ($company !== null && ! $check->allowed) {
                ($this->recordCreditOverride)($actor, $company, $check, (string) $overrideReason, 'change_reservation_company');
            }

            $oldCompanyId = $locked->company_id;
            $locked->company_id = $companyId;
            $locked->save();

            $routing = ($this->syncRoutingRules)($locked);

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

            return ReservationResult::created($reservation, $routing->unitsWithPostedRoomLines, $routing->unitAdvances);
        });
    }
}
