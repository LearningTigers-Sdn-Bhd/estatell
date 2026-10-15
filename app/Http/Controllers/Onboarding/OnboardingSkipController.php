<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SkipOnboardingStep;
use App\Actions\Onboarding\UnskipOnboardingStep;
use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SkipOnboardingStepRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class OnboardingSkipController extends Controller
{
    /**
     * Marks an optional step as skipped and opens the next listed step. A refused skip (locked, or already skipped)
     * shows an error toast and writes nothing.
     */
    public function store(SkipOnboardingStepRequest $request, Property $property, OnboardingStep $step, SkipOnboardingStep $skipOnboardingStep): RedirectResponse
    {
        if (! $skipOnboardingStep($property, $step, $request->user())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This section cannot be skipped right now.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':section skipped.', ['section' => $step->label()])]);

        $next = $step->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }

    /**
     * Removes a skip so the step is judged by its check again, and stays on the page.
     */
    public function destroy(Property $property, OnboardingStep $step, UnskipOnboardingStep $unskipOnboardingStep): RedirectResponse
    {
        if (! $unskipOnboardingStep($property, $step)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This section is not skipped.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':section is back on your list.', ['section' => $step->label()])]);

        return back();
    }
}
