<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Onboarding\SendOwnerSetupLink;
use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OwnerSetupLinkController extends Controller
{
    public function __invoke(Request $request, Property $property, SendOwnerSetupLink $sendOwnerSetupLink): RedirectResponse
    {
        if (! $sendOwnerSetupLink($request->user(), $property)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This property has no unverified owner.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Owner setup email sent.')]);

        return back();
    }
}
