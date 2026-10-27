<?php

namespace App\Services\FrontDesk;

use App\Actions\FrontDesk\UnitStayResult;
use App\Data\Reservations\CheckOutAsDayUseData;
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

    public function quote(ReservationUnit $unit, ?string $start = null, ?string $end = null, ?string $amount = null, ?string $reason = null): CheckOutAsDayUseData
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
        $checkedIn = $unit->checked_in_at?->setTimezone($zone);
        $data->checkedInTime = $checkedIn?->format('H:i');
        $startAt = $start === null ? $checkedIn : $this->time($data->date, $start, $zone);
        $endAt = $end === null ? now($zone) : $this->time($data->date, $end, $zone);
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
        $plan = RatePlan::query()->where('property_id', $unit->property_id)->active()
            ->where('pricing_basis', PricingBasis::DayUse)
            ->whereHas('roomTypes', fn ($types) => $types->whereKey($unit->room_type_id))
            ->orderBy('position')->orderBy('id')->first();
        $data->ratePlanId = $plan?->id;
        $data->ratePlanName = $plan?->name;

        if ($plan !== null) {
            $quote = $this->rateQuoteService->quoteDayUse($unit->roomType, $plan, $businessDate, $hours, ignoreStopSell: true);

            if ($quote->isRefused() && ! in_array($quote->refusal, [RateQuote::REFUSAL_HOURS_NOT_SOLD, RateQuote::REFUSAL_NO_HOUR_PRICE], true)) {
                $data->refusal = $quote->refusal;

                return $data;
            }

            $data->planPrice = $quote->isRefused() ? null : (string) $quote->amount->getAmount();
        }

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

        if ($data->overrideAmount !== null && ! filled($reason)) {
            $data->refusal = UnitStayResult::REFUSAL_OVERRIDE_REASON_REQUIRED;
        }

        return $data;
    }

    public function preview(ReservationUnit $unit, User $user, ?string $start = null, ?string $end = null, ?string $amount = null, ?string $reason = null): CheckOutAsDayUseData
    {
        $data = $this->quote($unit, $start, $end, $amount, $reason);
        $code = $data->hours === null ? null : TransactionCode::query()->where('property_id', $unit->property_id)->where('system_key', SystemTransactionCode::DayUseRevenue)->first();
        $data->noTaxes = $code !== null && ! $code->applicableTaxes()->exists();

        if ($data->refusal !== null) {
            $data->errors = $this->refusals->unitErrors($data->refusal);

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

    private function time(string $date, string $time, string $zone): ?CarbonImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($date.' '.$time, $zone);
    }
}
