<?php

namespace App\Http\Controllers\Pos;

use App\Data\Pos\PosOrderData;
use App\Data\Pos\PosShiftData;
use App\Data\Property\CurrencyData;
use App\Enums\PermissionEnum;
use App\Enums\PosOperationMode;
use App\Enums\PosShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\PosStaffPin;
use App\Models\PosTimeClock;
use App\Models\PosVoidReason;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Pos\InHouseGuestLookup;
use App\Services\Pos\OutletMenuSnapshot;
use App\Services\Pos\PosTerminal;
use App\Services\Pos\TableFloorState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosRegisterController extends Controller
{
    use RespondsToPos;

    /**
     * The launcher: outlets and registers, and the Pick-Name + PIN pad while nobody is signed in on a locked terminal.
     */
    public function index(Request $request, Property $property, PosTerminal $terminal): Response
    {
        $actor = $request->attributes->get('pos_actor');
        $locked = $terminal->isLocked($request, $property);

        abort_if($actor instanceof User && ! $actor->checkPermissionTo(PermissionEnum::PosSell->value), 403);

        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('native_pos_enabled', true)->where('active', true)->orderBy('name')->get();
        $registers = PosRegister::query()->where('property_id', $property->id)->where('active', true)->orderBy('name')->get()->groupBy('pos_outlet_id');
        $openShifts = PosShift::query()->where('property_id', $property->id)->where('status', '<>', PosShiftStatus::Closed)->get()->keyBy('pos_register_id');

        return Inertia::render('pos/index', [
            'locked' => $locked,
            'hasDevice' => $request->attributes->get('pos_device') !== null,
            'actor' => $actor instanceof User ? ['id' => $actor->id, 'name' => $actor->name] : null,
            'canShift' => $actor instanceof User && $actor->checkPermissionTo(PermissionEnum::PosShift->value),
            'staff' => $locked ? $this->staff($property) : [],
            'outlets' => $actor === null ? [] : $outlets->map(fn (PosOutlet $outlet): array => [
                'id' => $outlet->id,
                'name' => $outlet->name,
                'registers' => $registers->get($outlet->id, collect())->map(fn (PosRegister $register): array => [
                    'id' => $register->id,
                    'name' => $register->name,
                    'shiftNumber' => $openShifts->get($register->id)?->shift_number,
                    'shiftStatus' => $openShifts->get($register->id)?->status->label(),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function terminal(Request $request, Property $property, int $outlet, OutletMenuSnapshot $snapshot, TableFloorState $floors): Response
    {
        $actor = $this->actor($request);
        $model = PosOutlet::query()->where('property_id', $property->id)->where('native_pos_enabled', true)->findOrFail($outlet);
        $register = PosRegister::query()->where('pos_outlet_id', $model->id)->where('active', true)->find((int) $request->query('register'));
        $shift = $register === null ? null : PosShift::query()->where('pos_register_id', $register->id)->where('status', '<>', PosShiftStatus::Closed)->with(['outlet', 'register', 'openedBy'])->first();
        $orderId = (int) $request->query('order');
        $receiptId = (int) $request->query('receipt');

        $order = $orderId > 0 && $shift !== null
            ? PosOrder::query()->where('property_id', $property->id)->where('pos_shift_id', $shift->id)->with($this->orderRelations())->find($orderId)
            : null;
        $receipt = $receiptId > 0 && $shift !== null
            ? PosOrder::query()->where('property_id', $property->id)->where('pos_shift_id', $shift->id)->with($this->orderRelations())->find($receiptId)
            : null;

        return Inertia::render('pos/terminal', [
            'currency' => CurrencyData::forProperty($property),
            'outlet' => ['id' => $model->id, 'name' => $model->name, 'printReceiptAfterPayment' => $model->print_receipt_after_payment, 'roomChargeLimited' => $model->room_charge_limit !== null, 'payLater' => $model->operation_mode === PosOperationMode::PayLater],
            'clock' => ['required' => $model->require_clock_in, 'clockedIn' => PosTimeClock::query()->where('user_id', $actor->id)->where('pos_outlet_id', $model->id)->whereNull('clocked_out_at')->exists()],
            'floors' => $model->operation_mode === PosOperationMode::PayLater ? $floors->for($model) : [],
            'register' => $register === null ? null : ['id' => $register->id, 'name' => $register->name],
            'registers' => PosRegister::query()->where('pos_outlet_id', $model->id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'shift' => $shift === null ? null : PosShiftData::fromModel($shift),
            'menu' => $snapshot($model),
            'order' => $order === null ? null : PosOrderData::fromModel($order),
            'receipt' => $receipt === null ? null : PosOrderData::fromModel($receipt),
            'openOrders' => $shift === null ? [] : PosOrder::query()->where('pos_shift_id', $shift->id)->where('status', 'open')->orderBy('id')->with('table:id,code')->get(['id', 'order_number', 'grand_total', 'pos_table_id', 'opened_at'])->map(fn (PosOrder $open): array => ['id' => $open->id, 'orderNumber' => $open->order_number, 'grandTotal' => $open->grand_total, 'tableCode' => $open->table?->code, 'tableId' => $open->pos_table_id, 'openedAt' => $open->opened_at->toIso8601String()])->all(),
            'voidReasons' => PosVoidReason::query()->where('property_id', $property->id)->where('active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'label', 'requires_manager_pin']),
            'transactionCodes' => TransactionCode::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (TransactionCode $code): array => ['value' => (string) $code->id, 'label' => $code->name])->values()->all(),
            'staff' => $this->staff($property),
            'actor' => ['id' => $actor->id, 'name' => $actor->name],
            'can' => collect(['sell' => PermissionEnum::PosSell, 'shift' => PermissionEnum::PosShift, 'void' => PermissionEnum::PosVoid, 'discount' => PermissionEnum::PosDiscount, 'refund' => PermissionEnum::PosRefund])
                ->map(fn (PermissionEnum $permission): bool => $actor->checkPermissionTo($permission->value))->all(),
            'locked' => $request->hasSession() && app(PosTerminal::class)->isLocked($request, $property),
        ]);
    }

    public function guests(Request $request, Property $property, InHouseGuestLookup $lookup): JsonResponse
    {
        return response()->json(['guests' => $lookup->find($property, $request->query('q') === null ? null : (string) $request->query('q'), $request->query('room') === null ? null : (string) $request->query('room'))]);
    }

    /**
     * @return list<string>
     */
    public static function orderRelations(): array
    {
        return ['outlet', 'shift.register', 'diningOption', 'reservationUnit.reservation', 'refundFor', 'openedBy', 'table', 'lines.modifiers', 'lines.variant.item', 'payments', 'discounts'];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function staff(Property $property): array
    {
        return array_values(User::query()->where('property_id', $property->id)->whereNull('deactivated_at')
            ->whereIn('id', PosStaffPin::query()->where('property_id', $property->id)->where('active', true)->select('user_id'))
            ->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all());
    }
}
