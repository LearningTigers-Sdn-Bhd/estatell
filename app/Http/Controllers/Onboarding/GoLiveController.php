<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\GoLive;
use App\Actions\Onboarding\RequestGoLive;
use App\Actions\Onboarding\WithdrawGoLiveRequest;
use App\Data\Onboarding\GoLiveRequestData;
use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\GoLiveReview;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoLiveController extends Controller
{
    public function show(Property $property, GoLiveReview $review, GoLiveRequests $requests, PlatformSettings $platformSettings): Response|RedirectResponse
    {
        if ($property->status === PropertyStatus::Live) {
            return to_route('dashboard', ['property' => $property->code]);
        }

        $reviewData = $review->forProperty($property);
        $pending = $property->status === PropertyStatus::PendingApproval ? $requests->pending($property) : null;
        $sentBack = $requests->sentBack($property);

        return Inertia::render('onboarding/go-live', [
            'review' => $reviewData,
            'awaitingApproval' => $property->status === PropertyStatus::PendingApproval,
            'requiresApproval' => $platformSettings->current()->require_go_live_approval,
            'pendingRequest' => $pending === null ? null : GoLiveRequestData::fromReview($pending),
            'sentBack' => $sentBack === null ? null : GoLiveRequestData::fromReview($sentBack),
        ]);
    }

    public function store(Request $request, Property $property, GoLive $goLive, RequestGoLive $requestGoLive, PlatformSettings $platformSettings): RedirectResponse
    {
        if ($platformSettings->current()->require_go_live_approval) {
            if (! $requestGoLive($request->user(), $property)) {
                Inertia::flash('toast', ['type' => 'error', 'message' => __('This property cannot be sent for approval yet.')]);

                return back();
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent for approval.')]);

            return to_route('onboarding.go-live.show', ['property' => $property->code]);
        }

        if (! $goLive($request->user(), $property)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This property cannot go live yet.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your property is live.')]);
        Inertia::flash('wentLive', true);

        return to_route('dashboard', ['property' => $property->code]);
    }

    public function withdraw(Request $request, Property $property, WithdrawGoLiveRequest $withdrawGoLiveRequest): RedirectResponse
    {
        if (! $withdrawGoLiveRequest($request->user(), $property)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is no request to withdraw.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request withdrawn. You can make changes now.')]);

        return to_route('onboarding.go-live.show', ['property' => $property->code]);
    }
}
