<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\UpdateRegisterDisplayState;
use App\Data\Pos\PosOrderData;
use App\Data\Pos\PosShiftData;
use App\Data\Property\CurrencyData;
use App\Enums\PermissionEnum;
use App\Enums\PosCashMovementKind;
use App\Enums\PosDiscountType;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOperationMode;
use App\Enums\PosOrderStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Models\MealPeriod;
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
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Pos\CustomerDisplay;
use App\Services\Pos\DailyMealCheckInList;
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

        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->where('active', true)->orderBy('name')->get();
        $registers = PosRegister::query()->where('property_id', $property->id)->where('active', true)->orderBy('name')->get()->groupBy('pos_outlet_id');
        $openShifts = PosShift::query()->where('property_id', $property->id)->where('status', '<>', PosShiftStatus::Closed)->get()->keyBy('pos_register_id');

        return Inertia::render('pos/index', [
            'locked' => $locked,
            'hasDevice' => $request->attributes->get('pos_device') !== null,
            'actor' => $actor instanceof User ? ['id' => $actor->id, 'name' => $actor->name] : null,
            'clock' => $actor instanceof User ? [
                'clockedInOutletId' => PosTimeClock::query()->where('user_id', $actor->id)->whereNull('clocked_out_at')->value('pos_outlet_id'),
            ] : null,
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

    public function terminal(Request $request, Property $property, int $outlet, OutletMenuSnapshot $snapshot, TableFloorState $floors, CurrentBusinessDate $currentBusinessDate, UpdateRegisterDisplayState $updateRegisterDisplayState): Response
    {
        $actor = $this->actor($request);
        $model = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->findOrFail($outlet);
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

        if ($register !== null) {
            $this->syncCustomerDisplay($property, $register, $order, $updateRegisterDisplayState);
        }

        return Inertia::render('pos/terminal', [
            'currency' => CurrencyData::forProperty($property),
            'businessDate' => $currentBusinessDate->date($property)?->toDateString(),
            'prebill' => $request->boolean('prebill'),
            'outlet' => [
                'id' => $model->id,
                'name' => $model->name,
                'printReceiptAfterPayment' => $model->print_receipt_after_payment,
                'roomChargeLimited' => $model->room_charge_limit !== null,
                'roomChargeLimit' => $model->room_charge_limit,
                'payLater' => $model->operation_mode === PosOperationMode::PayLater,
                'roundingMode' => $model->rounding_mode->value,
                'roundingPolicy' => $model->rounding_policy->value,
                'safeClearingCodeId' => $model->safe_clearing_transaction_code_id,
                'requireReprintPin' => $model->require_reprint_pin,
                'requireRoomChargeSignature' => $model->require_room_charge_signature,
                'requireMealRedemptionConfirmation' => $model->require_meal_redemption_confirmation,
            ],
            'clock' => ['required' => $model->require_clock_in, 'clockedIn' => PosTimeClock::query()->where('user_id', $actor->id)->where('pos_outlet_id', $model->id)->whereNull('clocked_out_at')->exists()],
            'floors' => $model->operation_mode === PosOperationMode::PayLater ? $floors->for($model) : [],
            'floorVersion' => $model->operation_mode === PosOperationMode::PayLater ? $floors->version($model) : null,
            'register' => $register === null ? null : ['id' => $register->id, 'name' => $register->name],
            'registers' => PosRegister::query()->where('pos_outlet_id', $model->id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'shift' => $shift === null ? null : PosShiftData::fromModel($shift),
            'menu' => $snapshot($model),
            'order' => $order === null ? null : PosOrderData::fromModel($order),
            'receipt' => $receipt === null ? null : PosOrderData::fromModel($receipt),
            'openOrders' => $shift === null ? [] : PosOrder::query()->where('pos_shift_id', $shift->id)->where('status', 'open')->orderBy('id')->with('table:id,code')->withCount(['lines as draft_lines_count' => fn ($query) => $query->where('kitchen_status', PosKitchenStatus::Draft->value)])->get(['id', 'order_number', 'grand_total', 'pos_table_id', 'opened_at', 'source'])->map(fn (PosOrder $open): array => ['id' => $open->id, 'orderNumber' => $open->order_number, 'grandTotal' => $open->grand_total, 'tableCode' => $open->table?->code, 'tableId' => $open->pos_table_id, 'openedAt' => $open->opened_at->toIso8601String(), 'source' => $open->source->value, 'draftCount' => (int) $open->getAttribute('draft_lines_count')])->all(),
            'voidReasons' => PosVoidReason::query()->where('property_id', $property->id)->where('active', true)->orderBy('sort_order')->orderBy('label')->get(['id', 'label', 'requires_manager_pin']),
            'transactionCodes' => TransactionCode::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (TransactionCode $code): array => ['value' => (string) $code->id, 'label' => $code->name])->values()->all(),
            'cashMovementKinds' => PosCashMovementKind::options(),
            'tenderTypes' => PosTenderType::options(),
            'discountTypes' => PosDiscountType::options(),
            'staff' => $this->staff($property),
            'actor' => ['id' => $actor->id, 'name' => $actor->name],
            'can' => collect(['sell' => PermissionEnum::PosSell, 'shift' => PermissionEnum::PosShift, 'void' => PermissionEnum::PosVoid, 'discount' => PermissionEnum::PosDiscount, 'refund' => PermissionEnum::PosRefund])
                ->map(fn (PermissionEnum $permission): bool => $actor->checkPermissionTo($permission->value))->all(),
            'locked' => $request->hasSession() && app(PosTerminal::class)->isLocked($request, $property),
        ]);
    }

    public function guests(Request $request, Property $property, InHouseGuestLookup $lookup, CurrentBusinessDate $currentBusinessDate, DailyMealCheckInList $mealList): JsonResponse
    {
        $guests = $lookup->find($property, $request->query('q') === null ? null : (string) $request->query('q'), $request->query('room') === null ? null : (string) $request->query('room'));
        $date = $currentBusinessDate->date($property);

        $mealsByUnit = [];
        if ($date !== null && $guests !== []) {
            $unitIds = array_column($guests, 'reservation_unit_id');
            $periods = MealPeriod::query()->where('property_id', $property->id)->active()->ordered()->get();

            foreach ($periods as $period) {
                $list = $mealList->forPeriod($property, $date, $period);

                foreach ($list->rows as $row) {
                    if (in_array($row->reservationUnitId, $unitIds, true)) {
                        $mealsByUnit[$row->reservationUnitId][] = [
                            'meal_period_id' => $period->id,
                            'meal_period_name' => $period->name,
                            'entitled' => $row->entitledGuests,
                            'remaining' => $row->remainingGuests,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'guests' => array_map(
                fn (array $guest): array => [...$guest, 'meals' => $mealsByUnit[$guest['reservation_unit_id']] ?? []],
                $guests,
            ),
        ]);
    }

    public function floorVersion(Property $property, int $outlet, TableFloorState $floors): JsonResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->findOrFail($outlet);

        return response()->json(['version' => $floors->version($model)]);
    }

    /**
     * @return list<string>
     */
    public static function orderRelations(): array
    {
        return ['outlet', 'shift.register', 'diningOption', 'reservationUnit.reservation', 'refundFor', 'openedBy', 'table', 'lines.modifiers', 'lines.variant.item', 'payments', 'discounts'];
    }

    /**
     * Keep the customer-facing display in step with the check on screen. A settled order stays for its thank-you window even
     * when the cashier clears the register, so the guest still sees the receipt and change due.
     */
    private function syncCustomerDisplay(Property $property, PosRegister $register, ?PosOrder $order, UpdateRegisterDisplayState $updateRegisterDisplayState): void
    {
        if ($register->active_pos_order_id === $order?->id) {
            return;
        }

        if ($order === null && $register->active_pos_order_id !== null) {
            $previous = PosOrder::query()->where('property_id', $property->id)->find($register->active_pos_order_id);
            $settledRecently = $previous?->status === PosOrderStatus::Settled
                && $previous->settled_at !== null
                && $previous->settled_at->diffInSeconds(now()) <= CustomerDisplay::THANK_YOU_SECONDS;

            if ($settledRecently) {
                return;
            }
        }

        $updateRegisterDisplayState($property, $register, $order?->id, false);
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
