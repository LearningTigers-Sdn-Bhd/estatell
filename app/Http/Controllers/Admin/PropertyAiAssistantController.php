<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AiAssistant\SetAiMonthlyCap;
use App\Actions\AiAssistant\SetAiScopeAllowed;
use App\Enums\AiScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiMonthlyCapRequest;
use App\Http\Requests\Admin\UpdateAiScopeAllowedRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The AI assistant's child rows on the admin Features tab: which scopes the property may use, and its monthly cap.
 * See docs/34-ai-assistant/01-overview.md#scopes.
 */
class PropertyAiAssistantController extends Controller
{
    public function scope(UpdateAiScopeAllowedRequest $request, Property $property, AiScope $scope, SetAiScopeAllowed $setAiScopeAllowed): RedirectResponse
    {
        $allowed = $request->boolean('allowed');

        if ($setAiScopeAllowed($request->user(), $property, $scope, $allowed)) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => $allowed ? __(':scope allowed.', ['scope' => $scope->label()]) : __(':scope stopped.', ['scope' => $scope->label()]),
            ]);
        }

        return back(fallback: route('admin.properties.features', $property));
    }

    public function cap(UpdateAiMonthlyCapRequest $request, Property $property, SetAiMonthlyCap $setAiMonthlyCap): RedirectResponse
    {
        if ($setAiMonthlyCap($request->user(), $property, $request->capMicros())) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('AI assistant monthly cap saved.')]);
        }

        return back(fallback: route('admin.properties.features', $property));
    }
}
