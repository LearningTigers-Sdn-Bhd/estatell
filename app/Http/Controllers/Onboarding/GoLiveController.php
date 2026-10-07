<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\GoLive;
use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\GoLiveRequest;
use App\Models\Property;
use App\Services\Onboarding\GoLiveReadiness;
use App\Services\Platform\PlatformSettings;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GoLiveController extends Controller
{
    public function show(Property $property, GoLiveReadiness $readiness, PlatformSettings $platformSettings, PropertyToday $propertyToday): Response|RedirectResponse
    {
        if ($property->status === PropertyStatus::Live) {
            return to_route('dashboard', ['property' => $property->code]);
        }

        return Inertia::render('onboarding/go-live', [
            'checks' => $readiness->checks($property),
            'ready' => $readiness->isReady($property),
            'awaitingApproval' => $property->status === PropertyStatus::PendingApproval,
            'requiresApproval' => $platformSettings->current()->require_go_live_approval,
            'today' => $propertyToday->date($property)->toDateString(),
        ]);
    }

    public function store(GoLiveRequest $request, Property $property, GoLive $goLive): RedirectResponse
    {
        $status = $goLive($request->user(), $property, CarbonImmutable::parse($request->validated('opening_date')));

        if ($status === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This property cannot go live yet.')]);

            return back();
        }

        if ($status === PropertyStatus::PendingApproval) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted for approval.')]);

            return to_route('onboarding.go-live.show', ['property' => $property->code]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your property is live.')]);

        return to_route('dashboard', ['property' => $property->code]);
    }
}
