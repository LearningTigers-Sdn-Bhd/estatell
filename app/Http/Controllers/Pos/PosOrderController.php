<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\AddOrderLine;
use App\Actions\Pos\ApplyPosDiscount;
use App\Actions\Pos\ChangePosDiningOption;
use App\Actions\Pos\CreatePosOrder;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\PrintPreBill;
use App\Actions\Pos\RefundPosOrder;
use App\Actions\Pos\RemoveDraftLine;
use App\Actions\Pos\RemovePosDiscount;
use App\Actions\Pos\ResolvePosApproval;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\TransferOrderToRegister;
use App\Actions\Pos\UpdateDraftOrderLine;
use App\Actions\Pos\VoidOrderLine;
use App\Actions\Pos\VoidPosOrder;
use App\Data\Pos\PosOrderLineInputData;
use App\Data\Pos\PosRefundLineInputData;
use App\Data\Pos\PosTenderInputData;
use App\Enums\PermissionEnum;
use App\Enums\PosDiscountType;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\AddPosOrderLineRequest;
use App\Http\Requests\Pos\ApplyPosDiscountRequest;
use App\Http\Requests\Pos\ChangePosDiningOptionRequest;
use App\Http\Requests\Pos\FirePosOrderRequest;
use App\Http\Requests\Pos\PrintPreBillRequest;
use App\Http\Requests\Pos\RefundPosOrderRequest;
use App\Http\Requests\Pos\SettlePosOrderRequest;
use App\Http\Requests\Pos\StorePosOrderRequest;
use App\Http\Requests\Pos\TransferPosOrderRequest;
use App\Http\Requests\Pos\UpdatePosOrderLineRequest;
use App\Http\Requests\Pos\VoidPosLineRequest;
use App\Http\Requests\Pos\VoidPosOrderRequest;
use App\Models\PosDiningOption;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\PosVoidReason;
use App\Models\Property;
use App\Models\ReservationUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class PosOrderController extends Controller
{
    use RespondsToPos;

    public function store(StorePosOrderRequest $request, Property $property, CreatePosOrder $createPosOrder): RedirectResponse
    {
        $shift = PosShift::query()->where('property_id', $property->id)->where('pos_register_id', (int) $request->validated('register_id'))->where('status', PosShiftStatus::Open)->first();
        $option = PosDiningOption::query()->where('property_id', $property->id)->find((int) $request->validated('pos_dining_option_id'));
        $unit = $request->validated('reservation_unit_id') === null ? null : ReservationUnit::query()->where('property_id', $property->id)->find((int) $request->validated('reservation_unit_id'));

        if ($shift === null || $option === null) {
            $this->error(__('Open a shift on this register first.'));

            return back();
        }

        $result = $createPosOrder($this->actor($request), $property, $shift, $option, (string) ($request->validated('client_uuid') ?? Str::uuid()), (int) ($request->validated('guest_count') ?? 1), $unit);

        return $this->toast($result->refusal, __('Order opened.')) && $result->model instanceof PosOrder ? $this->terminal($property, $shift, $result->model) : back();
    }

    public function addLine(AddPosOrderLineRequest $request, Property $property, int $order, AddOrderLine $addOrderLine): RedirectResponse
    {
        $model = $this->order($property, $order);
        $input = new PosOrderLineInputData(
            (int) $request->validated('variant_id'),
            (int) ($request->validated('quantity') ?? 1),
            array_values(array_map(intval(...), $request->validated('modifier_option_ids') ?? [])),
            $request->validated('note'),
            $request->validated('unit_price'),
            (bool) $request->validated('cover_with_entitlement'),
        );

        $this->toast($addOrderLine($this->actor($request), $property, $model, $input)->refusal, __('Added.'));

        return $this->terminal($property, $model->shift, $model);
    }

    public function updateLine(UpdatePosOrderLineRequest $request, Property $property, int $order, int $line, UpdateDraftOrderLine $updateDraftOrderLine): RedirectResponse
    {
        $model = $this->order($property, $order);
        $this->toast($updateDraftOrderLine($this->actor($request), $property, $model, $this->line($model, $line), $request->validated('quantity') === null ? null : (int) $request->validated('quantity'), $request->validated('note'))->refusal, __('Line updated.'));

        return $this->terminal($property, $model->shift, $model);
    }

    /**
     * A draft line is removed; a fired line is voided with a reason (and a manager approval).
     */
    public function voidLine(VoidPosLineRequest $request, Property $property, int $order, int $line, RemoveDraftLine $removeDraftLine, VoidOrderLine $voidOrderLine, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->order($property, $order);
        $row = $this->line($model, $line);
        $actor = $this->actor($request);

        if ($row->kitchen_status->isLocked() === false && $model->prebill_print_count === 0) {
            $this->toast($removeDraftLine($actor, $property, $model, $row)->refusal, __('Line removed.'));

            return $this->terminal($property, $model->shift, $model);
        }

        $approval = $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosVoid, 'void line', ['order' => $model->id, 'line' => $row->id]);
        $reason = PosVoidReason::query()->where('property_id', $property->id)->find((int) $request->validated('void_reason_id'));

        if ($approval['error'] !== null || $reason === null) {
            $this->error($approval['error'] ?? __('Pick a void reason.'));

            return $this->terminal($property, $model->shift, $model);
        }

        $this->toast($voidOrderLine($actor, $property, $model, $row, $reason, $request->validated('reason_text'), $request->has('return_stock') ? $request->boolean('return_stock') : null, $approval['approver'])->refusal, __('Line voided.'));

        return $this->terminal($property, $model->shift, $model);
    }

    public function fire(FirePosOrderRequest $request, Property $property, int $order, FireOrderLines $fireOrderLines): RedirectResponse
    {
        $model = $this->order($property, $order);
        $ids = $request->validated('line_ids');
        $this->toast($fireOrderLines($this->actor($request), $property, $model, $ids === null ? null : array_values(array_map(intval(...), $ids)))->refusal, __('Sent to the kitchen.'));

        return $this->terminal($property, $model->shift, $model);
    }

    public function void(VoidPosOrderRequest $request, Property $property, int $order, VoidPosOrder $voidPosOrder, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->order($property, $order);
        $actor = $this->actor($request);
        $approval = $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosVoid, 'void order', ['order' => $model->id]);
        $reason = PosVoidReason::query()->where('property_id', $property->id)->find((int) $request->validated('void_reason_id'));

        if ($approval['error'] !== null || $reason === null) {
            $this->error($approval['error'] ?? __('Pick a void reason.'));

            return $this->terminal($property, $model->shift, $model);
        }

        $result = $voidPosOrder($actor, $property, $model, $reason, $request->validated('reason_text'), $request->has('return_stock') ? $request->boolean('return_stock') : null, $approval['approver']);

        return $this->toast($result->refusal, __('Order voided.')) ? $this->terminal($property, $model->shift, null) : $this->terminal($property, $model->shift, $model);
    }

    public function discount(ApplyPosDiscountRequest $request, Property $property, int $order, ApplyPosDiscount $applyPosDiscount, RemovePosDiscount $removePosDiscount, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->order($property, $order);
        $actor = $this->actor($request);
        $line = $request->validated('line_id') === null ? null : $this->line($model, (int) $request->validated('line_id'));
        $approval = $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosDiscount, 'discount', ['order' => $model->id]);

        if ($approval['error'] !== null) {
            $this->error($approval['error']);

            return $this->terminal($property, $model->shift, $model);
        }

        $type = PosDiscountType::tryFrom((string) $request->validated('type'));

        if ($request->boolean('remove')) {
            $this->toast($removePosDiscount($actor, $property, $model, $line, $approval['approver'])->refusal, __('Discount removed.'));
        } elseif ($type === null) {
            $this->error(__('Pick a discount type.'));
        } else {
            $this->toast($applyPosDiscount($actor, $property, $model, $type, (string) ($request->validated('value') ?? '0.00'), $line, $request->validated('reason'), $approval['approver'], $request->boolean('exclude_addons'))->refusal, __('Discount applied.'));
        }

        return $this->terminal($property, $model->shift, $model);
    }

    public function transfer(TransferPosOrderRequest $request, Property $property, int $order, TransferOrderToRegister $transferOrderToRegister): RedirectResponse
    {
        $model = $this->order($property, $order);
        $target = PosRegister::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('register_id'));
        $this->toast($transferOrderToRegister($this->actor($request), $property, $model, $target)->refusal, __('Order moved to :register.', ['register' => $target->name]));

        return back();
    }

    public function prebill(PrintPreBillRequest $request, Property $property, int $order, PrintPreBill $printPreBill, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->order($property, $order);
        $actor = $this->actor($request);
        $approval = $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosVoid, 'reprint pre-bill', ['order' => $model->id]);

        if ($approval['error'] !== null) {
            $this->error($approval['error']);

            return $this->terminal($property, $model->shift, $model);
        }

        $this->toast($printPreBill($actor, $property, $model, $approval['approver'])->refusal, __('Pre-bill ready.'));

        return $this->terminal($property, $model->shift, $model, ['prebill' => 1]);
    }

    public function diningOption(ChangePosDiningOptionRequest $request, Property $property, int $order, ChangePosDiningOption $changePosDiningOption, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->order($property, $order);
        $actor = $this->actor($request);
        $option = PosDiningOption::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('pos_dining_option_id'));
        $approval = $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosVoid, 'change dining option', ['order' => $model->id]);

        if ($approval['error'] !== null) {
            $this->error($approval['error']);

            return $this->terminal($property, $model->shift, $model);
        }

        $this->toast($changePosDiningOption($actor, $property, $model, $option, $approval['approver'])->refusal, __('Dining option changed.'));

        return $this->terminal($property, $model->shift, $model);
    }

    public function settle(SettlePosOrderRequest $request, Property $property, int $order, SettlePosOrder $settlePosOrder, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $model = $this->order($property, $order);
        $actor = $this->actor($request);
        $approval = $resolveApproval($request, $property, $model->outlet, $actor, PermissionEnum::PosVoid, 'room charge limit', ['order' => $model->id]);

        if ($approval['error'] !== null) {
            $this->error($approval['error']);

            return $this->terminal($property, $model->shift, $model);
        }

        $tenders = array_values(array_map(fn (array $row): PosTenderInputData => new PosTenderInputData(
            PosTenderType::from($row['tender_type']),
            (string) $row['amount'],
            isset($row['payment_method_id']) ? (int) $row['payment_method_id'] : null,
            isset($row['tendered_amount']) ? (string) $row['tendered_amount'] : null,
            isset($row['reservation_unit_id']) ? (int) $row['reservation_unit_id'] : null,
            $row['reference'] ?? null,
        ), $request->validated('tenders')));

        $result = $settlePosOrder($actor, $property, $model, $tenders, $request->boolean('guest_signed'), $request->boolean('meal_confirmed'), $approval['approver']);

        return $this->toast($result->refusal, __('Paid.'))
            ? to_route('pos.terminal', ['property' => $property, 'outlet' => $model->pos_outlet_id, 'register' => $model->shift->pos_register_id, 'receipt' => $model->id])
            : $this->terminal($property, $model->shift, $model);
    }

    public function refund(RefundPosOrderRequest $request, Property $property, int $order, RefundPosOrder $refundPosOrder, ResolvePosApproval $resolveApproval): RedirectResponse
    {
        $original = $this->order($property, $order);
        $actor = $this->actor($request);
        $approval = $resolveApproval($request, $property, $original->outlet, $actor, PermissionEnum::PosRefund, 'refund', ['order' => $original->id]);

        if ($approval['error'] !== null || $approval['approver'] === null) {
            $this->error($approval['error'] ?? __('A manager must approve a refund. Enter their PIN.'));

            return back();
        }

        $lines = array_values(array_map(fn (array $row): PosRefundLineInputData => new PosRefundLineInputData((int) $row['order_line_id'], (int) $row['quantity'], (bool) ($row['return_stock'] ?? false), (bool) ($row['restore_meal_entitlement'] ?? false)), $request->validated('lines')));
        $result = $refundPosOrder($actor, $property, $original, $lines, (string) $request->validated('reason'), (string) ($request->validated('client_uuid') ?? Str::uuid()), $approval['approver']);
        $this->toast($result->refusal, __('Refund recorded.'));

        return back();
    }

    private function order(Property $property, int $id): PosOrder
    {
        return PosOrder::query()->where('property_id', $property->id)->with(['outlet', 'shift'])->findOrFail($id);
    }

    private function line(PosOrder $order, int $id): PosOrderLine
    {
        return PosOrderLine::query()->where('pos_order_id', $order->id)->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function terminal(Property $property, ?PosShift $shift, ?PosOrder $order, array $extra = []): RedirectResponse
    {
        abort_if($shift === null, 404);

        return to_route('pos.terminal', ['property' => $property, 'outlet' => $shift->pos_outlet_id, 'register' => $shift->pos_register_id, ...($order === null ? [] : ['order' => $order->id]), ...$extra]);
    }
}
