<?php

namespace App\Http\Controllers\Rates;

use App\Actions\Rates\ExtendRates;
use App\Actions\Rates\SaveRates;
use App\Data\Property\WeekendNightsOptionData;
use App\Data\Rates\RateBulkPlanData;
use App\Data\Rates\RatePlanData;
use App\Data\Rates\RatePricingRoomTypeData;
use App\Enums\PermissionEnum;
use App\Enums\WeekendNights;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\RateBulkRequest;
use App\Http\Requests\Rates\RateCalendarRequest;
use App\Http\Requests\Rates\SaveRatesRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Property\PropertyToday;
use App\Services\Rates\RateCalendarService;
use App\Services\Rates\RatesEndingCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class RateCalendarController extends Controller
{
    /**
     * A date range of the calendar, 14 days from today by default. Room types, plans and filters are bounded lists
     * the page filters in the browser. Only a change of range asks the server again.
     */
    public function index(RateCalendarRequest $request, Property $property, PropertyToday $propertyToday, RateCalendarService $rateCalendarService): Response
    {
        $today = $propertyToday->date($property);
        [$from, $to] = $request->range($today);

        return Inertia::render('rates/calendar', [
            'calendar' => $rateCalendarService->forRange($property, $from, $to, $today, $property->settings->currency->value),
            'currency' => $property->settings->currency,
            'today' => $today->toDateString(),
            'weekendDays' => $property->settings->weekend_nights->isoWeekdays(),
            'canManage' => $request->user()->can(PermissionEnum::RatesManage->value),
        ]);
    }

    /**
     * The bulk edit page: pick a plan and a date range, then change prices and restrictions over the range.
     * `?plan=` picks the plan and defaults to the first active one. `?from=` and `?to=` prefill the dates.
     */
    public function bulk(RateBulkRequest $request, Property $property, PropertyToday $propertyToday, RatesEndingCheck $ratesEndingCheck): Response
    {
        $plans = $property->ratePlans()->active()->ordered()->with(['parent', 'roomTypes'])->get();
        $selected = $plans->firstWhere('id', $request->integer('plan')) ?? $plans->first();
        $today = $propertyToday->date($property);
        $pricedUntil = $selected === null ? [] : $ratesEndingCheck->pricedUntil($selected);

        return Inertia::render('rates/bulk', [
            'plans' => $plans->map(fn (RatePlan $plan): RateBulkPlanData => RateBulkPlanData::fromModel($plan))->values(),
            'ratePlan' => $selected === null ? null : RatePlanData::fromModel($selected),
            'roomTypes' => $selected === null ? [] : $selected->roomTypes
                ->whereNull('archived_at')
                ->sortBy(['position', 'id'])
                ->values()
                ->map(fn (RoomType $roomType): RatePricingRoomTypeData => RatePricingRoomTypeData::fromModel(
                    $roomType,
                    $pricedUntil[$roomType->id] ?? null,
                    $ratesEndingCheck->isEnding($pricedUntil[$roomType->id] ?? null, $today),
                )),
            'currency' => $property->settings->currency,
            'weekendNights' => $property->settings->weekend_nights,
            'weekendNightsOptions' => array_map(WeekendNightsOptionData::fromEnum(...), WeekendNights::cases()),
            'today' => $today->toDateString(),
            'from' => $request->validated('from') ?? '',
            'to' => $request->validated('to') ?? '',
        ]);
    }

    /**
     * Save prices and restrictions over a date range, then show the start of that range in the calendar.
     */
    public function bulkUpdate(SaveRatesRequest $request, Property $property, RatePlan $ratePlan, SaveRates $saveRates): RedirectResponse
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $written = $saveRates($request->user(), $ratePlan, $from, $to, $request->roomTypes(), $request->restrictions());

        if ($written === false) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The rate plan or room types changed. Reload the page and try again.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Rates saved for :count date.|Rates saved for :count dates.', $written)]);

        $lastShown = Date::parse($from)->addDays(RateCalendarRequest::MAX_DAYS - 1)->min(Date::parse($to));

        return to_route('rates.calendar', ['property' => $property, 'from' => $from, 'to' => $lastShown->toDateString()]);
    }

    /**
     * Save prices and restrictions for one plan from the calendar: one date from the cell editor, or a range.
     */
    public function update(SaveRatesRequest $request, Property $property, RatePlan $ratePlan, SaveRates $saveRates): RedirectResponse
    {
        $written = $saveRates($request->user(), $ratePlan, $request->string('from')->toString(), $request->string('to')->toString(), $request->roomTypes(), $request->restrictions());

        Inertia::flash('toast', $written === false
            ? ['type' => 'error', 'message' => __('The rate plan or room types changed. Reload the page and try again.')]
            : ['type' => 'success', 'message' => trans_choice('Rates saved for :count date.|Rates saved for :count dates.', $written)]);

        return back();
    }

    public function extend(Request $request, Property $property, RatePlan $ratePlan, PropertyToday $propertyToday, ExtendRates $extendRates): RedirectResponse
    {
        $written = $extendRates($request->user(), $ratePlan, $propertyToday->date($property));

        Inertia::flash('toast', match (true) {
            $written === false => ['type' => 'error', 'message' => __(':name cannot extend its prices. Reload the page and try again.', ['name' => $ratePlan->name])],
            $written === 0 => ['type' => 'error', 'message' => __('There are no prices to copy yet. Set prices first.')],
            default => ['type' => 'success', 'message' => trans_choice('Prices extended by :count date.|Prices extended by :count dates.', $written)],
        });

        return back();
    }
}
