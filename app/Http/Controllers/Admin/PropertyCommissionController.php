<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Commissions\CancelCommissionPeriod;
use App\Actions\Commissions\StartCommissionPeriod;
use App\Actions\Commissions\StopCommissionPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartCommissionPeriodRequest;
use App\Http\Requests\Admin\StopCommissionPeriodRequest;
use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Starts, stops and cancels a property's commission charging periods. See docs/30-commission-fees/05-platform-pages.md#property-commissions-tab.
 */
class PropertyCommissionController extends Controller
{
    public function start(StartCommissionPeriodRequest $request, Property $property, StartCommissionPeriod $startCommissionPeriod): RedirectResponse
    {
        $refusal = $startCommissionPeriod($request->user(), $property, $request->service(), $request->startsOn());

        Inertia::flash('toast', match ($refusal) {
            null => ['type' => 'success', 'message' => __(':service commission starts on :date.', ['service' => $request->service()->label(), 'date' => $request->startsOn()->format('j M Y')])],
            StartCommissionPeriod::REFUSAL_PAST_DATE => ['type' => 'error', 'message' => __('Choose today or a later date.')],
            StartCommissionPeriod::REFUSAL_ALREADY_OPEN => ['type' => 'error', 'message' => __('This service is already charged. Stop it first.')],
            default => ['type' => 'error', 'message' => __('Choose a date after the last period ends.')],
        });

        return to_route('admin.properties.commissions', $property);
    }

    public function stop(StopCommissionPeriodRequest $request, Property $property, PropertyCommissionPeriod $commissionPeriod, StopCommissionPeriod $stopCommissionPeriod): RedirectResponse
    {
        $refusal = $stopCommissionPeriod($request->user(), $commissionPeriod, $request->endsOn());

        Inertia::flash('toast', match ($refusal) {
            null => ['type' => 'success', 'message' => __(':service commission stops after :date.', ['service' => $commissionPeriod->service->label(), 'date' => $request->endsOn()->format('j M Y')])],
            StopCommissionPeriod::REFUSAL_PAST_DATE => ['type' => 'error', 'message' => __('Choose today or a later date.')],
            StopCommissionPeriod::REFUSAL_BEFORE_START => ['type' => 'error', 'message' => __('Choose a date on or after the start date.')],
            default => ['type' => 'error', 'message' => __('This period has already been stopped.')],
        });

        return to_route('admin.properties.commissions', $property);
    }

    public function cancel(Request $request, Property $property, PropertyCommissionPeriod $commissionPeriod, CancelCommissionPeriod $cancelCommissionPeriod): RedirectResponse
    {
        $refusal = $cancelCommissionPeriod($request->user(), $commissionPeriod);

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __(':service commission start cancelled.', ['service' => $commissionPeriod->service->label()])]
            : ['type' => 'error', 'message' => __('This period has started and can no longer be cancelled.')]);

        return to_route('admin.properties.commissions', $property);
    }
}
