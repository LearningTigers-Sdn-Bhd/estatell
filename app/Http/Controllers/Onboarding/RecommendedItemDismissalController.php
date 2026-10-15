<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\DismissRecommendedItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\DismissRecommendedItemRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RecommendedItemDismissalController extends Controller
{
    /**
     * Marks a Recommended next item as Not needed. A refused key shows an error toast and writes nothing.
     */
    public function store(DismissRecommendedItemRequest $request, Property $property, DismissRecommendedItem $dismissRecommendedItem): RedirectResponse
    {
        if (! $dismissRecommendedItem($property, $request->user(), $request->string('item')->toString())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This item cannot be marked as not needed.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked as not needed.')]);

        return back();
    }
}
