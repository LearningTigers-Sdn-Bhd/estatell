<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Commissions\ChangeCommissionRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCommissionRateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CommissionRateController extends Controller
{
    /**
     * Add a new rate for a service from a start date.
     */
    public function store(StoreCommissionRateRequest $request, ChangeCommissionRate $changeCommissionRate): RedirectResponse
    {
        $rate = $changeCommissionRate($request->user(), $request->service(), (string) $request->validated('rate'), $request->effectiveFrom());

        Inertia::flash('toast', $rate === null
            ? ['type' => 'error', 'message' => __('This service already has a rate from that date.')]
            : ['type' => 'success', 'message' => __('Commission rate saved.')]);

        return to_route('admin.settings.commissions');
    }
}
