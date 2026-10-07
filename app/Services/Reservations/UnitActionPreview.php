<?php

namespace App\Services\Reservations;

use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Reservations\AssignmentResult;
use App\Data\Reservations\CheckInAllPreviewData;
use App\Data\Reservations\UnitCheckInPreviewData;
use App\Data\Reservations\UnitCheckOutPreviewData;
use App\Data\Reservations\UnitDatesPreviewData;
use App\Data\Reservations\UnitEarlyDepartureData;
use App\Data\Reservations\UnitFeePreviewData;
use App\Data\Reservations\UnitLateCheckOutPreviewData;
use App\Data\Reservations\UnitMovePreviewData;
use App\Data\Reservations\UnitReinstatePreviewData;
use App\Enums\PermissionEnum;
use App\Enums\SellUnit;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\FreeUnits;
use App\Services\Catalogues\PolicyChargeCalculator;
use App\Services\Catalogues\TaxCalculator;
use App\Services\CityLedger\CreditCheck;
use App\Services\FrontDesk\CheckInCollection;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\Guests\CountryOptions;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use App\Services\Registration\RegistrationCardRequirement;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the unit action forms show when they open: the policy fee, the price after a reinstate and the price after a
 * date change. It reads only and never locks or writes. The Actions work everything out again inside their own
 * transaction, so a preview that is out of date is refused on submit (`fee_changed`) and never posts a wrong amount.
 * See docs/18-front-desk/01-check-in-and-check-out.md#how-the-forms-work.
 */
class UnitActionPreview
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PolicyChargeCalculator $policyChargeCalculator,
        private RateQuoteService $rateQuoteService,
        private RestrictionCheck $restrictionCheck,
        private ReservationRefusals $refusals,
        private RegistrationCardRequirement $registrationCardRequirement,
        private UnitNationality $unitNationality,
        private CreditCheck $creditCheck,
        private CheckInCollection $checkInCollection,
        private CheckOutSettlement $checkOutSettlement,
        private CountryOptions $countryOptions,
        private FreeUnits $freeUnits,
        private TaxCalculator $taxCalculator,
    ) {}

    /**
     * Tonight's room, the registration card status, the checks the user may override and Collect now.
     */
    public function checkIn(ReservationUnit $unit, User $user): UnitCheckInPreviewData
    {
        $property = $unit->property()->with('settings')->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null) {
            return UnitCheckInPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $tonight = $unit->nights()->with(['room', 'bed'])->whereDate('date', $businessDate->toDateString())->first();
        $cardRequired = (bool) $property->settings?->card_required_for_check_in;
        $needsNationality = $this->unitNationality->for($unit) === null
            && Tax::query()->where('property_id', $unit->property_id)->where('enabled', true)->where('foreign_guests_only', true)->exists();
        $company = $unit->reservation()->firstOrFail()->company;
        $credit = $company === null ? null : $this->creditCheck->check($company);
        $collection = $user->can(PermissionEnum::PaymentRecord->value) ? $this->checkInCollection->for($unit) : null;

        return new UnitCheckInPreviewData(
            room: $tonight?->room?->number ?? $tonight?->bed?->label,
            cardsMissing: $cardRequired ? $this->registrationCardRequirement->missingFor($unit, $property)->count() : 0,
            cardRequired: $cardRequired,
            canCheckInWithoutCard: $user->can(PermissionEnum::FrontDeskCheckInWithoutCard->value),
            needsNationality: $needsNationality,
            countries: $needsNationality ? $this->countryOptions->options() : [],
            creditExceeded: $credit !== null && ! $credit->allowed,
            canOverrideCredit: $user->can(PermissionEnum::CompanyCreditOverride->value),
            collection: $collection === null || $collection->isEmpty() ? null : $collection,
            error: null,
        );
    }

    /**
     * The rows of the group Check in all form: each Confirmed unit, ready when the business date is inside its stay.
     */
    public function checkInAll(Reservation $reservation, User $user): CheckInAllPreviewData
    {
        $property = $reservation->property()->with('settings')->firstOrFail();
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null) {
            return CheckInAllPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $today = $businessDate->toDateString();
        $cardRequired = (bool) $property->settings?->card_required_for_check_in;
        $rows = [];

        $units = $reservation->units()
            ->where('status', UnitStatus::Confirmed)
            ->with(['roomType', 'guests.guest', 'nights' => fn ($nights) => $nights->whereDate('date', $today), 'nights.room', 'nights.bed'])
            ->orderBy('id')
            ->get();

        foreach ($units as $unit) {
            $tonight = $unit->nights->first();
            $arrival = $unit->arrival_date->toDateString();
            $ready = $today >= $arrival && $today < $unit->departure_date->toDateString();
            $room = $tonight?->room?->number ?? $tonight?->bed?->label;

            $rows[] = [
                'unitId' => $unit->id,
                'label' => $unit->roomType->name,
                'guest' => $unit->guests->firstWhere('is_primary', true)?->guest->full_name,
                'room' => $room,
                'cardsMissing' => $cardRequired ? $this->registrationCardRequirement->missingFor($unit, $property)->count() : 0,
                'ready' => $ready,
                'note' => match (true) {
                    ! $ready && $today < $arrival => __('Arrives :date', ['date' => $unit->arrival_date->format('d M Y')]),
                    ! $ready => __('The stay has ended'),
                    $room === null => __('No room assigned for tonight'),
                    default => null,
                },
            ];
        }

        return new CheckInAllPreviewData($rows, $user->can(PermissionEnum::FrontDeskCheckInWithoutCard->value), null);
    }

    /**
     * The Move room form for a first night: the current room or bed, the room types the unit can move to and the free
     * rooms or beds of the chosen type (its own when none is chosen). An unknown or unlinked type falls back to the
     * unit's own.
     */
    public function move(ReservationUnit $unit, CarbonInterface $fromNight, ?int $roomTypeId): UnitMovePreviewData
    {
        $from = CarbonImmutable::instance($fromNight)->startOfDay();

        if ($from->lt($unit->arrival_date->startOfDay()) || $from->gte($unit->departure_date->startOfDay())) {
            return UnitMovePreviewData::refused($this->refusals->message(AssignmentResult::REFUSAL_NIGHT_INVALID));
        }

        $own = $unit->roomType()->firstOrFail();
        $linkedIds = $unit->ratePlan()->firstOrFail()->roomTypes()->pluck('room_types.id');
        $types = RoomType::query()
            ->where('property_id', $unit->property_id)
            ->where(fn ($query) => $query
                ->where(fn ($linked) => $linked->whereIn('id', $linkedIds)->where('sell_unit', $own->sell_unit)->active())
                ->orWhere('id', $own->id))
            ->orderBy('name')
            ->get(['id', 'name']);
        $chosen = $types->firstWhere('id', $roomTypeId) ?? $types->firstWhere('id', $own->id);
        $night = $unit->nights()->with(['room', 'bed'])->whereDate('date', $from->toDateString())->first();

        return new UnitMovePreviewData(
            current: $night?->room?->number ?? $night?->bed?->label,
            roomTypes: $types->map(fn (RoomType $type): array => ['id' => $type->id, 'name' => $type->name])->all(),
            roomTypeId: $chosen?->id,
            sellsBeds: $own->sell_unit === SellUnit::Bed,
            units: $chosen === null ? [] : $this->freeUnits->forStay(RoomType::query()->findOrFail($chosen->id), $from, $unit->departure_date, $unit->id),
            error: null,
        );
    }

    /**
     * The unit folio balance and the security deposit held.
     */
    public function checkOut(ReservationUnit $unit, User $user): UnitCheckOutPreviewData
    {
        $preview = $this->checkOutSettlement->for($unit, $user->can(PermissionEnum::PaymentRefund->value));
        $businessDate = $this->currentBusinessDate->date($unit->property()->firstOrFail());

        if ($unit->status !== UnitStatus::CheckedIn || $businessDate === null || $businessDate->toDateString() >= $unit->departure_date->toDateString()) {
            return $preview;
        }

        return $this->earlyCheckOut($unit, $businessDate->toImmutable(), $preview);
    }

    /**
     * The early departure fee `EarlyCheckOut` will post, with its tax, and the nights it removes.
     */
    private function earlyCheckOut(ReservationUnit $unit, CarbonImmutable $businessDate, UnitCheckOutPreviewData $preview): UnitCheckOutPreviewData
    {
        $property = $unit->property()->firstOrFail();
        $policy = $this->policy($unit, SystemTransactionCode::EarlyDepartureRevenue);
        $code = $property->transactionCodes()->where('system_key', SystemTransactionCode::EarlyDepartureRevenue->value)->where('active', true)->first();

        if ($policy === null || $code === null) {
            return UnitCheckOutPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
        }

        $charge = $this->policyChargeCalculator->calculate($policy, $unit, $businessDate);
        $tax = $this->taxCalculator->calculate($property, $code, $charge->amount, '1', $this->unitNationality->for($unit));

        if (! $tax->isCalculated()) {
            return UnitCheckOutPreviewData::refused($this->refusals->message((string) $tax->refusalReason));
        }

        $preview->early = new UnitEarlyDepartureData(
            departure: $unit->departure_date->toDateString(),
            nightsRemoved: $unit->nights()->whereDate('date', '>=', $businessDate->toDateString())->count(),
            fee: (string) $charge->amount->getAmount(),
            feeTotal: (string) $tax->grossAmount->getAmount(),
            withinFreeWindow: $charge->withinFreeWindow,
        );

        return $preview;
    }

    /**
     * The late check-out policy fee and the times the form shows.
     */
    public function lateCheckOut(ReservationUnit $unit): UnitLateCheckOutPreviewData
    {
        $policy = $this->policy($unit, SystemTransactionCode::LateCheckoutRevenue);

        if ($policy === null) {
            return UnitLateCheckOutPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
        }

        return new UnitLateCheckOutPreviewData(
            fee: (string) $this->policyChargeCalculator->calculate($policy, $unit)->amount->getAmount(),
            departureTime: substr($unit->departure_time, 0, 5),
            standardTime: substr((string) $unit->property()->with('settings')->firstOrFail()->settings?->check_out_time, 0, 5),
            error: null,
        );
    }

    /**
     * The fee of the cancellation or no-show policy on the business date, for the Cancel unit and Mark no-show forms.
     */
    public function fee(ReservationUnit $unit, SystemTransactionCode $code): UnitFeePreviewData
    {
        $businessDate = $this->currentBusinessDate->date($unit->property()->firstOrFail());

        if ($businessDate === null) {
            return UnitFeePreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $policy = $this->policy($unit, $code);

        if ($policy === null) {
            return UnitFeePreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
        }

        $charge = $this->policyChargeCalculator->calculate($policy, $unit);
        $isCancellation = $code === SystemTransactionCode::CancellationRevenue;

        return new UnitFeePreviewData(
            fee: (string) $charge->amount->getAmount(),
            withinFreeWindow: $charge->withinFreeWindow,
            freeUntilDays: $isCancellation ? $policy->free_until_days : null,
            daysBeforeArrival: $isCancellation ? (int) $businessDate->diffInDays($unit->arrival_date->toImmutable()->startOfDay(), false) : null,
            unpostedAddOns: $unit->addOns()->whereNull('folio_line_id')->count(),
            error: null,
        );
    }

    /**
     * The unit total as booked and as `ReinstateUnit` will price it again (a stay override keeps its night amount).
     */
    public function reinstate(ReservationUnit $unit): UnitReinstatePreviewData
    {
        $currency = $this->currency($unit);
        $nights = $unit->nights()->orderBy('date')->get();
        $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
            $unit->ratePlan()->firstOrFail(),
            $unit->roomType()->firstOrFail(),
            $unit->arrival_date->toImmutable()->startOfDay(),
            $unit->departure_date->toImmutable()->startOfDay(),
            $unit->adults,
            $this->childAges($unit),
            1,
        ));

        if ($quote->isRefused()) {
            return UnitReinstatePreviewData::refused($this->refusals->message((string) $quote->refusal, $quote->refusalDate));
        }

        $booked = $nights->keyBy(fn (UnitNight $night): string => $night->date->toDateString());
        $new = Money::zero($currency);

        foreach ($quote->nights as $price) {
            $override = $booked->get($price->date->toDateString())?->override_amount;
            $new = $new->plus($override ?? $price->net());
        }

        return new UnitReinstatePreviewData(
            previousTotal: (string) $this->total($nights, $currency)->getAmount(),
            newTotal: (string) $new->getAmount(),
            error: null,
        );
    }

    /**
     * The unit total after the dates change, as `ChangeUnitDates` will work it out: nights that stay keep their
     * stored prices and added nights are quoted alone, run by run.
     */
    public function dates(ReservationUnit $unit, CarbonInterface $arrival, CarbonInterface $departure): UnitDatesPreviewData
    {
        $newArrival = CarbonImmutable::instance($arrival)->startOfDay();
        $newDeparture = CarbonImmutable::instance($departure)->startOfDay();
        $oldArrival = $unit->arrival_date->toImmutable()->startOfDay();
        $oldDeparture = $unit->departure_date->toImmutable()->startOfDay();

        if ($newDeparture->lte($newArrival)) {
            return UnitDatesPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_INVALID_STAY));
        }

        $currency = $this->currency($unit);
        $nights = $unit->nights()->orderBy('date')->get();
        $previous = $this->total($nights, $currency);
        $kept = $nights->filter(fn (UnitNight $night): bool => $night->date->gte($newArrival) && $night->date->lt($newDeparture));
        $total = $this->total($kept, $currency);
        $added = $this->addedRuns($newArrival, $newDeparture, $oldArrival, $oldDeparture);
        $roomType = $unit->roomType()->firstOrFail();
        $ratePlan = $unit->ratePlan()->firstOrFail();

        foreach ($added as $run) {
            $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
                $ratePlan,
                $roomType,
                $run[0],
                $run[array_key_last($run)]->addDay(),
                $unit->adults,
                $this->childAges($unit),
                1,
            ));

            if ($quote->isRefused()) {
                return UnitDatesPreviewData::refused($this->refusals->message((string) $quote->refusal, $quote->refusalDate));
            }

            $total = $total->plus($quote->total);
        }

        $isEarlyDeparture = $newDeparture->lt($oldDeparture) && $newArrival->equalTo($oldArrival);
        $fee = null;

        if ($isEarlyDeparture) {
            $policy = $this->policy($unit, SystemTransactionCode::EarlyDepartureRevenue);

            if ($policy === null) {
                return UnitDatesPreviewData::refused($this->refusals->message(UnitStayResult::REFUSAL_POLICY_NOT_FOUND));
            }

            $fee = (string) $this->policyChargeCalculator->calculate($policy, $unit, $newDeparture)->amount->getAmount();
        }

        return new UnitDatesPreviewData(
            previousTotal: (string) $previous->getAmount(),
            newTotal: (string) $total->getAmount(),
            difference: (string) $total->minus($previous)->getAmount(),
            nights: (int) $newArrival->diffInDays($newDeparture),
            isEarlyDeparture: $isEarlyDeparture,
            fee: $fee,
            restricted: $added !== [] && $this->restrictionCheck->violations($ratePlan->id, $roomType->id, $newArrival, $newDeparture) !== [],
            error: null,
        );
    }

    private function policy(ReservationUnit $unit, SystemTransactionCode $code): ?RoomRevenuePolicy
    {
        return RoomRevenuePolicy::query()->where('property_id', $unit->property_id)->where('code', $code)->first();
    }

    private function currency(ReservationUnit $unit): string
    {
        return $unit->property()->firstOrFail()->settings->currency->value;
    }

    /**
     * @return list<int>
     */
    private function childAges(ReservationUnit $unit): array
    {
        return $unit->childAges()->pluck('age')->map(fn (mixed $age): int => (int) $age)->all();
    }

    /**
     * @param  iterable<UnitNight>  $nights
     */
    private function total(iterable $nights, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($nights as $night) {
            $total = $total->plus($night->amount);
        }

        return $total;
    }

    /**
     * The nights of the new stay that the unit does not hold now, as runs of consecutive dates.
     *
     * @return list<non-empty-list<CarbonImmutable>>
     */
    private function addedRuns(CarbonImmutable $newArrival, CarbonImmutable $newDeparture, CarbonImmutable $oldArrival, CarbonImmutable $oldDeparture): array
    {
        $runs = [];

        for ($night = $newArrival; $night->lt($newDeparture); $night = $night->addDay()) {
            if ($night->gte($oldArrival) && $night->lt($oldDeparture)) {
                continue;
            }

            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][array_key_last($runs[$last])]->addDay()->equalTo($night)) {
                $runs[$last][] = $night;
            } else {
                $runs[] = [$night];
            }
        }

        return $runs;
    }
}
