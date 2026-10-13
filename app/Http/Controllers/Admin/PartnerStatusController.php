<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\ReactivatePartner;
use App\Actions\Platform\SuspendPartner;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PartnerStatusController extends Controller
{
    public function suspend(Request $request, Partner $partner, SuspendPartner $suspendPartner): RedirectResponse
    {
        Inertia::flash('toast', $suspendPartner($request->user(), $partner)
            ? ['type' => 'success', 'message' => __('Partner suspended.')]
            : ['type' => 'error', 'message' => __('This partner cannot be suspended.')]);

        return back(fallback: route('admin.partners.index'));
    }

    public function reactivate(Request $request, Partner $partner, ReactivatePartner $reactivatePartner): RedirectResponse
    {
        Inertia::flash('toast', $reactivatePartner($request->user(), $partner)
            ? ['type' => 'success', 'message' => __('Partner reactivated.')]
            : ['type' => 'error', 'message' => __('This partner is not suspended.')]);

        return back(fallback: route('admin.partners.suspended'));
    }
}
