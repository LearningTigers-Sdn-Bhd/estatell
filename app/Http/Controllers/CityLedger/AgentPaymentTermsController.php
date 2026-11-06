<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\SaveAgentPaymentTerms;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\AgentPaymentTermsRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Agent payments: when a company that pays per booking pays its deposit and the rest. See
 * docs/15-city-ledger/06-agent-payment-schedule.md.
 */
class AgentPaymentTermsController extends Controller
{
    public function edit(Property $property): Response
    {
        abort_if($property->settings === null, 404);

        return Inertia::render('settings/agent-payments', [
            'terms' => [
                'depositPercent' => $property->settings->agent_deposit_percent,
                'depositHours' => $property->settings->agent_deposit_hours,
                'fullPaymentDays' => $property->settings->agent_full_payment_days,
            ],
        ]);
    }

    public function update(AgentPaymentTermsRequest $request, Property $property, SaveAgentPaymentTerms $save): RedirectResponse
    {
        $save($request->user(), $property, $request->terms());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agent payment terms saved.')]);

        return back();
    }
}
