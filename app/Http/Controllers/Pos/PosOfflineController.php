<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\IssuePosMenuSnapshot;
use App\Actions\Pos\ResolvePosApproval;
use App\Actions\Pos\SyncOfflinePosOrders;
use App\Data\Property\CurrencyData;
use App\Enums\PermissionEnum;
use App\Enums\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SyncOfflinePosOrdersRequest;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\PosStaffPin;
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
        $property->loadMissing('settings');
        $outlet = $this->outlet($property, $request);
        $shift = PosShift::query()->where('pos_outlet_id', $outlet->id)->where('status', PosShiftStatus::Open->value)->with('register')->first();

        return Inertia::render('pos/offline', [
            'outlet' => ['id' => $outlet->id, 'name' => $outlet->name],
            'shiftId' => $shift?->id,
            'registerId' => $shift?->pos_register_id,
            'registerName' => $shift?->register?->name,
            'currency' => CurrencyData::forProperty($property),
            'staff' => PosStaffPin::query()
                ->where('property_id', $property->id)
                ->where('active', true)
                ->with('user:id,name')
                ->get()
                ->filter(fn (PosStaffPin $pin): bool => $pin->user !== null)
                ->map(fn (PosStaffPin $pin): array => ['id' => $pin->user_id, 'name' => $pin->user->name])
                ->values()
                ->all(),
        ]);
    }

    public function snapshot(Request $request, Property $property, IssuePosMenuSnapshot $issuePosMenuSnapshot): JsonResponse
    {
        return response()->json($issuePosMenuSnapshot($property, $this->outlet($property, $request)));
    }

    public function sync(SyncOfflinePosOrdersRequest $request, Property $property, SyncOfflinePosOrders $syncOfflinePosOrders, ResolvePosApproval $resolveApproval): JsonResponse
    {
        $outlet = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->findOrFail((int) $request->validated('outlet_id'));
        $actor = $this->actor($request);
        $approver = null;

        if ($request->boolean('approve') || ($request->filled('approver_id') && $request->filled('approver_pin'))) {
            $approval = $resolveApproval($request, $property, $outlet, $actor, PermissionEnum::PosManage, 'offline_sync', ['outlet' => $outlet->id]);

            if ($approval['error'] !== null || $approval['approver'] === null) {
                return response()->json([
                    'error' => $approval['error'] ?? __('A manager must approve overriding offline price drift or an expired snapshot. Enter their PIN.'),
                ], 422);
            }

            $approver = $approval['approver'];
        }

        return response()->json([
            'results' => $syncOfflinePosOrders($actor, $property, $outlet, $request->validated('orders'), $approver),
        ]);
    }

    private function outlet(Property $property, Request $request): PosOutlet
    {
        return PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->findOrFail((int) $request->query('outlet'));
    }
}
