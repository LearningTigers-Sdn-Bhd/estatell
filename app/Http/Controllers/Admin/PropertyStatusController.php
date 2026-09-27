<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\ReactivateProperty;
use App\Actions\Platform\SuspendProperty;
use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PropertyStatusController extends Controller
{
    public function suspend(Request $request, Property $property, SuspendProperty $suspendProperty): RedirectResponse
    {
        Inertia::flash('toast', $suspendProperty($request->user(), $property)
            ? ['type' => 'success', 'message' => __('Property suspended.')]
            : ['type' => 'error', 'message' => __('This property cannot be suspended.')]);

        return back(fallback: route('admin.properties.show', $property));
    }

    public function reactivate(Request $request, Property $property, ReactivateProperty $reactivateProperty): RedirectResponse
    {
        Inertia::flash('toast', $reactivateProperty($request->user(), $property)
            ? ['type' => 'success', 'message' => __('Property reactivated.')]
            : ['type' => 'error', 'message' => __('This property is not suspended.')]);

        return back(fallback: route('admin.properties.show', $property));
    }
}
