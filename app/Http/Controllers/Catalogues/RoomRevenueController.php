<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\UpdateRoomRevenueTaxes;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Catalogues\RoomRevenuePreviewData;
use App\Data\Property\CurrencyData;
use App\Enums\SystemTransactionCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\RoomRevenueTaxesRequest;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Catalogues\RoomRevenuePreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomRevenueController extends Controller
{
    /**
     * Show the taxes on room revenue and a sample room night calculated with the saved ones.
     */
    public function index(Property $property, RoomRevenuePreview $roomRevenuePreview): Response
    {
        abort_if($property->settings === null, 404);

        $roomRevenue = $property->transactionCodes()
            ->where('system_key', SystemTransactionCode::RoomRevenue->value)
            ->with('applicableTaxes')
            ->first();
        abort_if($roomRevenue === null, 404);
        $taxIds = array_values($roomRevenue->applicableTaxes->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->all());

        return Inertia::render('settings/room-revenue/index', [
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax)),
            'taxIds' => $taxIds,
            'preview' => RoomRevenuePreviewData::fromCalculation($roomRevenuePreview->calculate($property, $taxIds)),
            'currency' => CurrencyData::forProperty($property),
            'hasDayUse' => $property->ratePlans()->dayUse()->exists(),
        ]);
    }

    /**
     * Calculate the sample room night for a selection that is not saved yet.
     */
    public function preview(RoomRevenueTaxesRequest $request, Property $property, RoomRevenuePreview $roomRevenuePreview): JsonResponse
    {
        abort_if($property->settings === null, 404);

        return response()->json(RoomRevenuePreviewData::fromCalculation($roomRevenuePreview->calculate($property, $request->taxIds())));
    }

    public function update(RoomRevenueTaxesRequest $request, Property $property, UpdateRoomRevenueTaxes $updateRoomRevenueTaxes): RedirectResponse
    {
        $updateRoomRevenueTaxes($property, $request->user(), $request->taxIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room revenue taxes saved.')]);

        return to_route('settings.room-revenue.index', $property);
    }
}
