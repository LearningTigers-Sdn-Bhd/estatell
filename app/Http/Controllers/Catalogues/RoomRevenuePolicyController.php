<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\SaveRoomRevenuePolicy;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Catalogues\RoomRevenuePolicyData;
use App\Enums\Locale;
use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\RoomRevenuePolicyRequest;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\Tax;
use App\Services\Catalogues\RoomRevenuePolicyPreview;
use App\Services\Catalogues\RoomRevenueTaxIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomRevenuePolicyController extends Controller
{
    /**
     * Show one policy tab: the form and what the saved policy charges on a sample stay.
     */
    public function show(Property $property, PolicyTab $tab, RoomRevenuePolicyPreview $preview, RoomRevenueTaxIds $roomRevenueTaxIds): Response
    {
        abort_if($property->settings === null, 404);

        $policy = $this->policyFor($property, $tab);

        return Inertia::render('settings/room-revenue/policy', [
            'tab' => $tab->value,
            'policy' => RoomRevenuePolicyData::fromModel($policy, $roomRevenueTaxIds->forPolicy($property, $policy)),
            'roomTaxIds' => $roomRevenueTaxIds->forRoom($property),
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax)),
            'preview' => $preview->calculate($property, $policy),
            'currency' => $property->settings->currency,
            'chargeModes' => PolicyChargeMode::options(),
            'rateBases' => PolicyRateBasis::options(),
            'scopes' => $tab->scopeOptions(),
            'locales' => Locale::options(),
            'hasDayUse' => $property->ratePlans()->dayUse()->exists(),
        ]);
    }

    /**
     * Calculate the sample stay for a form that is not saved yet.
     */
    public function preview(RoomRevenuePolicyRequest $request, Property $property, PolicyTab $tab, RoomRevenuePolicyPreview $preview): JsonResponse
    {
        abort_if($property->settings === null, 404);

        return response()->json($preview->calculate($property, $request->unsavedPolicy(), $request->ownTaxIds()));
    }

    public function update(RoomRevenuePolicyRequest $request, Property $property, PolicyTab $tab, SaveRoomRevenuePolicy $saveRoomRevenuePolicy): RedirectResponse
    {
        $this->policyFor($property, $tab);

        $saveRoomRevenuePolicy($property, $request->user(), $tab->code(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':policy policy saved.', ['policy' => $tab->label()])]);

        return to_route('settings.room-revenue.policies.show', [$property, $tab]);
    }

    private function policyFor(Property $property, PolicyTab $tab): RoomRevenuePolicy
    {
        return $property->roomRevenuePolicies()->where('code', $tab->code()->value)->firstOrFail();
    }
}
