<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\UpdateDayUseTaxes;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Catalogues\RoomRevenuePreviewData;
use App\Enums\SystemTransactionCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\RoomRevenueTaxesRequest;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Catalogues\RoomRevenuePreview;
use App\Services\Catalogues\RoomRevenueTaxIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomRevenueDayUseController extends Controller
{
    /**
     * The Day use tab: the taxes on the DAY_USE code and a sample day use calculated with the saved ones.
     */
    public function show(Property $property, RoomRevenueTaxIds $roomRevenueTaxIds, RoomRevenuePreview $roomRevenuePreview): Response
    {
        $this->ensureAvailable($property);

        $taxIds = $roomRevenueTaxIds->linkedTo($property, SystemTransactionCode::DayUseRevenue);

        return Inertia::render('settings/room-revenue/day-use', [
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax)),
            'taxIds' => $taxIds,
            'preview' => RoomRevenuePreviewData::fromCalculation($roomRevenuePreview->calculate($property, $taxIds)),
            'currency' => $property->settings->currency,
        ]);
    }

    /**
     * Calculate the sample day use for a selection that is not saved yet.
     */
    public function preview(RoomRevenueTaxesRequest $request, Property $property, RoomRevenuePreview $roomRevenuePreview): JsonResponse
    {
        $this->ensureAvailable($property);

        return response()->json(RoomRevenuePreviewData::fromCalculation($roomRevenuePreview->calculate($property, $request->taxIds())));
    }

    public function update(RoomRevenueTaxesRequest $request, Property $property, UpdateDayUseTaxes $updateDayUseTaxes): RedirectResponse
    {
        $this->ensureAvailable($property);

        Inertia::flash('toast', $updateDayUseTaxes($property, $request->user(), $request->taxIds())
            ? ['type' => 'success', 'message' => __('Day use taxes saved.')]
            : ['type' => 'error', 'message' => __('The Day use code is missing. Check the transaction codes.')]);

        return to_route('settings.room-revenue.day-use.show', $property);
    }

    /**
     * The tab exists only for a property that has a Day use plan, active or archived.
     */
    private function ensureAvailable(Property $property): void
    {
        abort_if($property->settings === null || ! $property->ratePlans()->dayUse()->exists(), 404);
    }
}
