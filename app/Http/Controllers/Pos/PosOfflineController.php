<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\IssuePosMenuSnapshot;
use App\Actions\Pos\SyncOfflinePosOrders;
use App\Enums\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SyncOfflinePosOrdersRequest;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Walk-in offline selling. The register stores the menu snapshot the server issues, sells from it while the connection is
 * down, and sends the sales back to be replayed. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class PosOfflineController extends Controller
{
    use RespondsToPos;

    public function show(Request $request, Property $property): Response
    {
        $outlet = $this->outlet($property, $request);
        $shift = PosShift::query()->where('pos_outlet_id', $outlet->id)->where('status', PosShiftStatus::Open->value)->first();

        return Inertia::render('pos/offline', [
            'outlet' => ['id' => $outlet->id, 'name' => $outlet->name],
            'shiftId' => $shift?->id,
            'registerId' => $shift?->pos_register_id,
            'registerName' => $shift?->register?->name,
        ]);
    }

    public function snapshot(Request $request, Property $property, IssuePosMenuSnapshot $issuePosMenuSnapshot): JsonResponse
    {
        return response()->json($issuePosMenuSnapshot($property, $this->outlet($property, $request)));
    }

    public function sync(SyncOfflinePosOrdersRequest $request, Property $property, SyncOfflinePosOrders $syncOfflinePosOrders): JsonResponse
    {
        $outlet = PosOutlet::query()->where('property_id', $property->id)->where('native_pos_enabled', true)->findOrFail((int) $request->validated('outlet_id'));

        return response()->json(['results' => $syncOfflinePosOrders($this->actor($request), $property, $outlet, $request->validated('orders'))]);
    }

    private function outlet(Property $property, Request $request): PosOutlet
    {
        return PosOutlet::query()->where('property_id', $property->id)->where('native_pos_enabled', true)->findOrFail((int) $request->query('outlet'));
    }
}
