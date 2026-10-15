<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\GoLive;
use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Onboarding\GoLiveReview;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoLiveController extends Controller
{
    public function show(Property $property, GoLiveReview $review, PlatformSettings $platformSettings): Response|RedirectResponse
    {
        if ($property->status === PropertyStatus::Live) {
            return to_route('dashboard', ['property' => $property->code]);
        }

        $reviewData = $review->forProperty($property);

        return Inertia::render('onboarding/go-live', [
            'review' => $reviewData,
            'awaitingApproval' => $property->status === PropertyStatus::PendingApproval,
            'requiresApproval' => $platformSettings->current()->require_go_live_approval,
        ]);
    }

    public function store(Request $request, Property $property, GoLive $goLive): RedirectResponse
    {
        $status = $goLive($request->user(), $property);

        if ($status === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This property cannot go live yet.')]);

            return back();
        }

        if ($status === PropertyStatus::PendingApproval) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted for approval.')]);

            return to_route('onboarding.go-live.show', ['property' => $property->code]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your property is live.')]);
        Inertia::flash('wentLive', true);

        return to_route('dashboard', ['property' => $property->code]);
    }
}
