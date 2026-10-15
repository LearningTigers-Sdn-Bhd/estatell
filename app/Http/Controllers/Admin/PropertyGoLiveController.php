<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Onboarding\ApproveGoLive;
use App\Actions\Onboarding\RejectGoLive;
use App\Data\Platform\AdminGoLiveReviewData;
use App\Enums\PropertyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveGoLiveRequest;
use App\Http\Requests\Admin\RejectGoLiveRequest;
use App\Models\Property;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\GoLiveReview;
use App\Services\Property\PropertyToday;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PropertyGoLiveController extends Controller
{
    public function show(Property $property, GoLiveRequests $requests, GoLiveReview $review, PropertyToday $propertyToday): Response|RedirectResponse
    {
        $pending = $property->status === PropertyStatus::PendingApproval ? $requests->pending($property) : null;

        if ($pending === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This property is not waiting for approval.')]);

            return to_route('admin.properties.show', $property->code);
        }

        $property->load('partner');

        return Inertia::render('admin/properties/go-live', [
            'goLive' => AdminGoLiveReviewData::fromReview(
                $property,
                $pending,
                $propertyToday->date($property)->toDateString(),
                $review->forProperty($property, withDetails: true),
            ),
        ]);
    }

    public function approve(ApproveGoLiveRequest $request, Property $property, ApproveGoLive $approveGoLive): RedirectResponse
    {
        if (! $approveGoLive($request->user(), $property, $request->openingDate())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This request cannot be approved.')]);

            return to_route('admin.properties.show', $property->code);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approved. :property is live.', ['property' => $property->name])]);

        return to_route('admin.properties.show', $property->code);
    }

    public function reject(RejectGoLiveRequest $request, Property $property, RejectGoLive $rejectGoLive): RedirectResponse
    {
        if (! $rejectGoLive($request->user(), $property, $request->validated('note'), $request->flaggedSteps())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This request cannot be sent back.')]);

            return to_route('admin.properties.show', $property->code);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent back to the owner.')]);

        return to_route('admin.properties.show', $property->code);
    }
}
