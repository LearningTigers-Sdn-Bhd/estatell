<?php

namespace App\Services\FrontDesk;

use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\CheckOutAsDayUseData;
use App\Data\Reservations\DayUsePlanOptionData;
use App\Data\Reservations\UnitPendingLineData;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\RatePlan;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitDayUse;
use App\Models\User;
use App\Services\Folio\DayUseChargeQuote;
use App\Services\Folio\FolioPostability;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\NightChargeQuote;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Platform\Impersonation;
use App\Services\Property\PropertyToday;
use App\Services\Rates\RateQuote;
use App\Services\Rates\RateQuoteService;
use App\Services\Reservations\ReservationRefusals;
use App\Services\Reservations\UnitNationality;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

class CheckOutAsDayUseQuote
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
        private RateQuoteService $rateQuoteService,
        private DayUseChargeQuote $dayUseChargeQuote,
        private NightChargeQuote $nightChargeQuote,
        private UnitNationality $unitNationality,
        private ScheduledFolioRows $scheduledFolioRows,
        private FolioRouter $folioRouter,
        private FolioPostability $folioPostability,
        private CheckOutSettlement $checkOutSettlement,
        private Impersonation $impersonation,
        private ReservationRefusals $refusals,
        private UnpostedAddOnsAfter $unpostedAddOnsAfter,
    ) {}

    public function quote(ReservationUnit $unit, ?string $start = null, ?string $end = null, ?string $amount = null, ?string $reason = null, ?int $ratePlanId = null, bool $noPlan = false): CheckOutAsDayUseData
    {
        $data = new CheckOutAsDayUseData;

        if ($unit->status !== UnitStatus::CheckedIn) {
            $data->refusal = UnitStayResult::REFUSAL_INVALID_STATUS;
        } elseif ($unit->stay_type === StayType::DayUse) {
            $data->refusal = UnitStayResult::REFUSAL_DAY_USE_UNIT;
        } elseif ($unit->roomType->sell_unit === SellUnit::Bed) {
            $data->refusal = UnitStayResult::REFUSAL_BED_UNIT;
        }

        if ($data->refusal !== null) {
            return $data;
        }

        $property = $unit->property;
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null || $unit->arrival_date->toDateString() !== $businessDate->toDateString() || $unit->departure_date->lte($unit->arrival_date)) {
            $data->refusal = UnitStayResult::REFUSAL_NOT_SAME_DAY;

            return $data;
        }

        $data->date = $businessDate->toDateString();

        if ($this->propertyToday->date($property)->toDateString() !== $data->date) {
            $data->refusal = UnitStayResult::REFUSAL_PAST_MIDNIGHT;

            return $data;
        }

        $zone = $property->settings->time_zone ?? config('app.timezone');
        $checkedIn = $unit->checked_in_at?->setTimezone($zone)->startOfMinute();
        $data->checkedInTime = $checkedIn?->format('H:i');
        $startAt = $start === null ? $checkedIn : $this->time($data->date, $start, $zone);
        $endAt = $end === null ? now($zone)->toImmutable()->startOfMinute() : $this->time($data->date, $end, $zone);
        $data->start = $startAt?->format('H:i:s');
        $data->end = $endAt?->format('H:i:s');

        if ($startAt === null || $startAt->toDateString() !== $data->date || $startAt->format('H:i:s') >= '23:00:00') {
            $data->refusal = UnitStayResult::REFUSAL_START_TIME_INVALID;

            return $data;
        }

        if ($endAt === null || $endAt->toDateString() !== $data->date || $endAt->lte($startAt)) {
            $data->refusal = UnitStayResult::REFUSAL_HOURS_INVALID;

            return $data;
        }

        $hours = (int) ceil($startAt->diffInSeconds($endAt) / 3600);
        $midnight = $startAt->addDay()->startOfDay();
        $data->midnightLimited = $startAt->addHours($hours)->gte($midnight);

        if ($data->midnightLimited) {
            $hours = (int) ceil($startAt->diffInSeconds($midnight) / 3600) - 1;
        }

        $data->blockEnd = $startAt->addHours($hours)->format('H:i:s');
        $data->hours = $hours;
        $plans = RatePlan::query()->where('property_id', $unit->property_id)->active()
            ->where('pricing_basis', PricingBasis::DayUse)
            ->whereHas('roomTypes', fn ($types) => $types->whereKey($unit->room_type_id))
            ->orderBy('position')->orderBy('id')->get();
        $prices = [];
        $refused = [];
        $notes = [];

        foreach ($plans as $candidate) {
            $quote = $this->rateQuoteService->quoteDayUse($unit->roomType, $candidate, $businessDate, $hours, ignoreStopSell: true);
            $reasons = $quote->isRefused() ? $this->refusalReasons($candidate, $quote->refusal, $businessDate) : null;
            $prices[$candidate->id] = $quote->isRefused() ? null : (string) $quote->amount->getAmount();
            $refused[$candidate->id] = $quote->refusal;
            $notes[$candidate->id] = $reasons[1] ?? null;
            $data->planOptions[] = new DayUsePlanOptionData($candidate->id, $candidate->name, $prices[$candidate->id], $reasons[0] ?? null);
        }

        $data->perNightPlanName = $unit->ratePlan?->name;
        $plan = null;

        if (! $noPlan && $ratePlanId !== null) {
            $plan = $plans->firstWhere('id', $ratePlanId);

            if ($plan === null) {
                $data->refusal = ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED;

                return $data;
            }

            if ($refused[$plan->id] !== null) {
                $data->refusal = $refused[$plan->id];

                return $data;
            }
        } elseif (! $noPlan) {
            $plan = $plans->first(fn (RatePlan $candidate): bool => $refused[$candidate->id] === null);
            $data->planNote = $plan === null && $plans->isNotEmpty() ? $notes[$plans->first()->id] : null;
        }

        $data->ratePlanId = $plan?->id;
        $data->ratePlanName = $plan?->name;
        $data->planPrice = $plan === null ? null : $prices[$plan->id];

        if ($amount === null && $data->planPrice === null) {
            $data->refusal = UnitStayResult::REFUSAL_AMOUNT_REQUIRED;

            return $data;
        }

        if ($amount !== null && preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1) {
            $data->refusal = 'override_invalid';

            return $data;
        }

        $currency = $property->settings->currency->getCurrencyCode();
        $data->amount = (string) Money::of($amount ?? $data->planPrice, $currency)->getAmount();
        $data->overrideAmount = $data->planPrice === $data->amount ? null : $data->amount;

        return $data;
    }

    public function preview(ReservationUnit $unit, User $user, ?string $start = null, ?string $end = null, ?string $amount = null, ?string $reason = null, ?int $ratePlanId = null, bool $noPlan = false): CheckOutAsDayUseData
    {
        $data = $this->quote($unit, $start, $end, $amount, $reason, $ratePlanId, $noPlan);
        $code = $data->hours === null ? null : TransactionCode::query()->where('property_id', $unit->property_id)->where('system_key', SystemTransactionCode::DayUseRevenue)->first();
        $data->noTaxes = $code !== null && ! $code->applicableTaxes()->exists();

        if ($data->refusal !== null) {
            $data->errors = $this->refusals->unitErrors($data->refusal);

            if ($ratePlanId !== null && in_array($data->refusal, [RateQuote::REFUSAL_HOURS_NOT_SOLD, RateQuote::REFUSAL_NO_HOUR_PRICE], true)) {
                $data->errors = ['rate_plan_id' => array_values($data->errors)[0]];
            }

            return $data;
        }

        $data->droppedAddOns = array_values($this->unpostedAddOnsAfter->query($unit, $unit->arrival_date)->with('chargeItem')->orderBy('service_date')->orderBy('id')->get()
            ->map(fn (UnitAddOn $addOn): string => __(':item × :quantity · :date', ['item' => $addOn->chargeItem->name, 'quantity' => $addOn->quantity, 'date' => $addOn->service_date->format('j M')]))
            ->all());
        $proposed = clone $unit;
        $proposed->stay_type = StayType::DayUse;
        $proposed->departure_date = $unit->arrival_date;
        $plan = $data->ratePlanId === null ? $unit->ratePlan : RatePlan::query()->where('property_id', $unit->property_id)->findOrFail($data->ratePlanId);
        $proposed->setRelation('ratePlan', $plan->loadMissing('inclusions.chargeItem.transactionCode'));
        $block = new UnitDayUse(['date' => $data->date, 'starts_at' => $data->start, 'ends_at' => $data->blockEnd, 'hours' => $data->hours, 'amount' => $data->amount]);
        $property = $unit->property;

        if ($code === null) {
            $data->refusal = 'charge_not_found';
            $data->errors = $this->refusals->unitErrors($data->refusal);

            return $data;
        }

        $quote = $this->dayUseChargeQuote->quote($property, $this->nightChargeQuote->propertyTaxes($property), $proposed, $block, $code, $this->unitNationality->for($unit));
        $data->refusal = $quote->refusal ?? $quote->taxRefusal();

        if ($data->refusal !== null) {
            $data->errors = $this->refusals->unitErrors($data->refusal);

            return $data;
        }

        $own = $this->checkOutSettlement->folios($unit)->modelKeys();
        $ownLines = [];

        foreach ($quote->charges as $charge) {
            $target = $this->folioRouter->targetFor($proposed, $charge->code);

            if ($target !== null && $target->status !== FolioStatus::Open) {
                $data->refusal = $this->folioPostability->refusalFor($target);
                $data->errors = $this->refusals->unitErrors($data->refusal);

                return $data;
            }

            foreach ($this->scheduledFolioRows->chargeRows($charge, $data->date, $data->overrideAmount !== null, null) as $row) {
                $line = new UnitPendingLineData($row->description, $row->amount, $row->level);
                $data->lines[] = $line;

                if ($target === null || in_array($target->id, $own, true)) {
                    $ownLines[] = $line;
                }
            }
        }

        $data->settlement = $this->checkOutSettlement->for($proposed, $this->impersonation->mayRefund($user), $user->can(PermissionEnum::PaymentRecord->value), $user->can(PermissionEnum::FolioTransfer->value), $ownLines);
        $data->folioLines = [
            ...FolioLine::query()->whereIn('folio_id', $own)->orderBy('id')->get()
                ->map(fn (FolioLine $line): UnitPendingLineData => new UnitPendingLineData($line->description, $line->amount, $line->parent_line_id === null ? 0 : 1))->all(),
            ...$ownLines,
        ];

        if ($data->settlement->refundDefault !== '0.00') {
            $data->folioLines[] = new UnitPendingLineData(__('Refund to guest'), $data->settlement->refundDefault, 0);
        }

        return $data;
    }

    /**
     * The short reason a plan is disabled and the longer note for the default falling to No plan.
     *
     * @return array{0: string, 1: string}
     */
    private function refusalReasons(RatePlan $plan, string $refusal, CarbonImmutable $date): array
    {
        if ($refusal === RateQuote::REFUSAL_HOURS_NOT_SOLD) {
            $range = ($plan->day_use_min_hours ?? 1).'–'.($plan->day_use_max_hours ?? 24);

            return [__('Sells :range hours', ['range' => $range]), __(':plan sells :range hours.', ['plan' => $plan->name, 'range' => $range])];
        }

        if ($refusal === RateQuote::REFUSAL_NO_HOUR_PRICE) {
            $day = $date->format('j M');

            return [__('No price for :date', ['date' => $day]), __(':plan has no price for :date.', ['plan' => $plan->name, 'date' => $day])];
        }

        $message = array_values($this->refusals->unitErrors($refusal))[0];

        return [$message, $message];
    }

    private function time(string $date, string $time, string $zone): ?CarbonImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($date.' '.$time, $zone)->startOfMinute();
    }
}
