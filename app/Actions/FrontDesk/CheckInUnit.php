<?php

namespace App\Actions\FrontDesk;

use App\Actions\CityLedger\RecordCreditOverride;
use App\Actions\Folio\PostCharge;
use App\Actions\Reservations\SyncReservationStatus;
use App\Data\Folio\NewChargeData;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\User;
use App\Services\CityLedger\CreditCheck;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Registration\RegistrationCardRequirement;
use App\Services\Reservations\UnitNationality;
use Illuminate\Support\Facades\DB;

class CheckInUnit
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private RegistrationCardRequirement $registrationCardRequirement,
        private UnitNationality $unitNationality,
        private CreditCheck $creditCheck,
        private RecordCreditOverride $recordCreditOverride,
        private PostCharge $postCharge,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Check one unit in: set `checked_in_at` and `UnitStatus::CheckedIn`, post its undated add-ons and sync the
     * reservation status. The unit row is locked first, so a double click cannot check it in twice. Every check runs
     * before the first write, so a refusal writes and logs nothing. An add-on that cannot post refuses the whole
     * check-in and rolls the status change back.
     *
     * `$withoutCard` skips the registration card rule, and `$creditOverrideReason` passes a company over its credit
     * limit. The route passes each only to a user with `front-desk.check-in-without-card` or `company.credit-override`.
     * Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, bool $withoutCard = false, ?string $creditOverrideReason = null): UnitStayResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $withoutCard, $creditOverrideReason): UnitStayResult {
                $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

                if (! $locked->status->canTransitionTo(UnitStatus::CheckedIn)) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
                }

                $property = $locked->property;
                $businessDate = $this->currentBusinessDate->date($property);

                if ($businessDate === null) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
                }

                $today = $businessDate->toDateString();

                if ($today < $locked->arrival_date->toDateString()) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_ARRIVAL_NOT_REACHED);
                }

                if ($today >= $locked->departure_date->toDateString()) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_STAY_ENDED);
                }

                $tonight = $locked->nights()->whereDate('date', $today)->first();

                if ($tonight === null || ($tonight->room_id === null && $tonight->bed_id === null)) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_ROOM_ASSIGNED);
                }

                if (! $withoutCard
                    && $property->settings?->card_required_for_check_in
                    && $this->registrationCardRequirement->missingFor($locked, $property)->isNotEmpty()) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_CARD_MISSING);
                }

                if ($this->unitNationality->for($locked) === null
                    && Tax::query()->where('property_id', $locked->property_id)->where('enabled', true)->where('foreign_guests_only', true)->exists()) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_NATIONALITY_REQUIRED);
                }

                $company = $locked->reservation->company;
                $check = $company === null ? null : $this->creditCheck->check($company);
                $overridden = $check !== null && ! $check->allowed;

                if ($overridden && trim((string) $creditOverrideReason) === '') {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_CREDIT_LIMIT_EXCEEDED);
                }

                $locked->status = UnitStatus::CheckedIn;
                $locked->checked_in_at = now();
                $locked->save();

                $this->postUndatedAddOns($actor, $locked);

                if ($overridden) {
                    ($this->recordCreditOverride)($actor, $company, $check, (string) $creditOverrideReason, 'check_in');
                }

                activity()
                    ->performedOn($locked->reservation)
                    ->causedBy($actor)
                    ->event('reservation.unit_checked_in')
                    ->withProperties([
                        'property_id' => $locked->property_id,
                        'reservation_unit_id' => $locked->id,
                        'room_id' => $tonight->room_id,
                        'bed_id' => $tonight->bed_id,
                        'without_card' => $withoutCard,
                        'business_date' => $today,
                    ])
                    ->log('Unit checked in');

                ($this->syncReservationStatus)($locked->reservation);

                return UnitStayResult::done($locked);
            });
        } catch (UnitStayRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * @throws UnitStayRefused when an add-on is refused by `PostCharge`
     */
    private function postUndatedAddOns(User $actor, ReservationUnit $unit): void
    {
        $addOns = $unit->addOns()->whereNull('service_date')->whereNull('folio_line_id')->orderBy('id')->get();

        foreach ($addOns as $addOn) {
            $result = ($this->postCharge)($actor, $unit, new NewChargeData(
                chargeItemId: $addOn->charge_item_id,
                transactionCodeId: null,
                description: null,
                quantity: $addOn->quantity,
                unitAmount: $addOn->unit_amount,
                unitAddOnId: $addOn->id,
            ));

            if ($result->isRefused()) {
                throw new UnitStayRefused(UnitStayResult::refused((string) $result->refusal));
            }
        }
    }
}
