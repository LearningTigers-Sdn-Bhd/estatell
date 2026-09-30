<?php

namespace App\Http\Controllers\Rates;

use App\Actions\Rates\ExtendRates;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\RateCalendarRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\Property\PropertyToday;
use App\Services\Rates\RateCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'today' => $today->toDateString(),
            'canManage' => $request->user()->can(PermissionEnum::RatesManage->value),
        ]);
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
