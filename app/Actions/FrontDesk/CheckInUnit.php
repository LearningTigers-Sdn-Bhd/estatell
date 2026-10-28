<?php

namespace App\Actions\FrontDesk;

use App\Actions\CityLedger\RecordCreditOverride;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostDayUseBlock;
use App\Actions\NightAudit\PostMissedNights;
use App\Actions\Reservations\SyncReservationStatus;
use App\Data\Folio\NewChargeData;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Events\StayCheckedIn;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\CityLedger\CreditCheck;
use App\Services\FrontDesk\StayDay;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Registration\RegistrationCardRequirement;
use App\Services\Reservations\UnitNationality;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
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
        private PostDayUseBlock $postDayUseBlock,
        private AutoAssignRooms $autoAssignRooms,
        private SyncReservationStatus $syncReservationStatus,
        private PostMissedNights $postMissedNights,
        private StayDay $stayDay,
    ) {}

    /**
     * Check one unit in: set `checked_in_at` and `UnitStatus::CheckedIn`, post its undated add-ons and sync the
     * reservation status. The unit row is locked first, so a double click cannot check it in twice. Every check runs
     * before the first write, so a refusal writes and logs nothing. An add-on that cannot post refuses the whole
     * check-in and rolls the status change back.
     *
     * `$withoutCard` skips the registration card rule, and `$creditOverrideReason` passes a company over its credit
     * limit. The route passes each only to a user with `front-desk.check-in-without-card` or `company.credit-override`.
     * A unit with no room tonight gets one from `AutoAssignRooms` when the property has `auto_assign_rooms` on.
     *
     * A day use unit checks in only on its date (`arrival_not_reached` before it, `stay_ended` after it) and takes its
     * room from `unit_day_uses` (`no_room_assigned` without one). Its block is posted at check-in, because the guest
     * leaves before the night audit: first every unposted add-on of the unit, dated or not, then the block through
     * `PostDayUseBlock` (with the check-in day as its service date when `$asOf` is given). A posting refusal rolls the whole check-in back.
     * A unit that arrives after its arrival date gets the nights it missed: `PostMissedNights` runs after the check-in,
     * so each night before the business date posts with its own service date, and a refusal rolls the check-in back.
     * The check-in day is the business date, or the calendar date for a unit that arrives on it while the night audit
     * is pending (`StayDay`): it decides the arrival check, `stay_ended`, the room and the `business_date` of the log.
     * `$asOf` is the moment the guest really checked in, for a past stay entered late (`CreatePastStay`): it sets
     * `checked_in_at`, and the arrival, the end of the stay and the room are checked on that day, not on the business date. Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, bool $withoutCard = false, ?string $creditOverrideReason = null, ?CarbonImmutable $asOf = null): UnitStayResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $withoutCard, $creditOverrideReason, $asOf): UnitStayResult {
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
                $checkInDay = $asOf?->toDateString() ?? $this->stayDay->forCheckIn($locked, $businessDate)->toDateString();

                if ($checkInDay < $locked->arrival_date->toDateString()) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_ARRIVAL_NOT_REACHED);
                }

                $isDayUse = $locked->stay_type === StayType::DayUse;

                if ($isDayUse ? $checkInDay > $locked->arrival_date->toDateString() : $checkInDay >= $locked->departure_date->toDateString()) {
                    return UnitStayResult::refused(UnitStayResult::REFUSAL_STAY_ENDED);
                }

                if ($isDayUse) {
                    $roomId = $locked->dayUse()->value('room_id');
                    $bedId = null;

                    if ($roomId === null) {
                        return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_ROOM_ASSIGNED);
                    }
                } else {
                    $tonight = $locked->nights()->whereDate('date', $checkInDay)->first();

                    if ($tonight !== null && $tonight->room_id === null && $tonight->bed_id === null && $property->settings?->auto_assign_rooms) {
                        ($this->autoAssignRooms)($actor, $locked);
                        $tonight = $locked->nights()->whereDate('date', $checkInDay)->first();
                    }

                    if ($tonight === null || ($tonight->room_id === null && $tonight->bed_id === null)) {
                        return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_ROOM_ASSIGNED);
                    }

                    $roomId = $tonight->room_id;
                    $bedId = $tonight->bed_id;
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
                $locked->checked_in_at = $asOf ?? now();
                $locked->save();

                if ($isDayUse) {
                    $this->postAddOns($actor, $locked, $locked->addOns()->whereNull('folio_line_id')->orderBy('id')->get());
                    $this->postBlock($actor, $locked, $today, $asOf === null ? null : $checkInDay);
                } else {
                    $this->postAddOns($actor, $locked, $locked->addOns()->whereNull('service_date')->whereNull('folio_line_id')->orderBy('id')->get());
                    $this->postMissedNights($actor, $locked);
                }

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
                        'room_id' => $roomId,
                        'bed_id' => $bedId,
                        'without_card' => $withoutCard,
                        'business_date' => $asOf === null ? $checkInDay : $today,
                    ])
                    ->log('Unit checked in');

                ($this->syncReservationStatus)($locked->reservation);

                StayCheckedIn::dispatch($locked->property_id, $locked->id);

                return UnitStayResult::done($locked);
            });
        } catch (UnitStayRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * @param  Collection<int, UnitAddOn>  $addOns
     *
     * @throws UnitStayRefused when an add-on is refused by `PostCharge`
     */
    private function postAddOns(User $actor, ReservationUnit $unit, Collection $addOns): void
    {
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

    /**
     * @throws UnitStayRefused when a missed night is refused by `PostMissedNights`
     */
    private function postMissedNights(User $actor, ReservationUnit $unit): void
    {
        $result = ($this->postMissedNights)($actor, $unit);

        if ($result->isRefused()) {
            throw new UnitStayRefused(UnitStayResult::refused((string) $result->refusal));
        }
    }

    /**
     * @throws UnitStayRefused when the block is refused by `PostDayUseBlock`
     */
    private function postBlock(User $actor, ReservationUnit $unit, string $today, ?string $serviceDate): void
    {
        $result = ($this->postDayUseBlock)($actor, $unit, $today, $serviceDate);

        if ($result->isRefused()) {
            throw new UnitStayRefused(UnitStayResult::refused((string) $result->refusal));
        }
    }
}
