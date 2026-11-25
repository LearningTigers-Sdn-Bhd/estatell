<?php

namespace App\Http\Controllers\Pos;

use App\Actions\EInvoice\SubmitPosWalkInEInvoice;
use App\Actions\Pos\ChangePosPaymentMethod;
use App\Actions\Pos\RefundPosOrder;
use App\Data\Pos\PosOrderData;
use App\Data\Pos\PosOrderRowData;
use App\Data\Pos\PosRefundLineInputData;
use App\Data\Property\CurrencyData;
use App\Enums\PermissionEnum;
use App\Enums\PosOrderStatus;
use App\Enums\PosTenderType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\ChangePosPaymentRequest;
use App\Http\Requests\Pos\RefundPosOrderRequest;
use App\Http\Requests\Pos\SubmitPosEInvoiceRequest;
use App\Models\PosOrder;
use App\Models\PosOrderFolioLink;
use App\Models\PosOrderLine;
use App\Models\PosOrderPayment;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * Finance receipts: the list, one receipt with its reprint, refund and e-invoice, from a back-office session. The route's
 * `can:` middleware checks the permission of the signed-in user.
 */
class PosOrderReceiptController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property): Response
    {
        $orders = PosOrder::query()->where('property_id', $property->id)
            ->whereIn('status', [PosOrderStatus::Settled, PosOrderStatus::Voided])
            ->when($request->query('outlet'), fn ($query, $outlet) => $query->where('pos_outlet_id', (int) $outlet))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', (string) $status))
            ->when($request->query('from'), fn ($query, $from) => $query->whereDate('business_date', '>=', (string) $from))
            ->when($request->query('to'), fn ($query, $to) => $query->whereDate('business_date', '<=', (string) $to))
            ->when($request->query('q'), fn ($query, $search) => $query->where('order_number', 'ilike', '%'.Str::of((string) $search)->replace(['%', '_'], '')->toString().'%'))
            ->with('outlet')->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('finance/pos-orders/index', [
            'currency' => CurrencyData::forProperty($property),
            'orders' => PosOrderRowData::collect($orders->through(PosOrderRowData::fromModel(...)), PaginatedDataCollection::class),
            'outlets' => PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['outlet', 'status', 'from', 'to', 'q']),
        ]);
    }

    public function show(Request $request, Property $property, int $order): Response
    {
        $model = PosOrder::query()->where('property_id', $property->id)->with(PosRegisterController::orderRelations())->findOrFail($order);
        $refunded = PosOrderLine::query()->whereIn('refund_of_order_line_id', $model->lines->pluck('id'))->selectRaw('refund_of_order_line_id, sum(quantity) as quantity')->groupBy('refund_of_order_line_id')->pluck('quantity', 'refund_of_order_line_id')->map(fn ($quantity): string => (string) $quantity)->all();
        $user = $request->user();

        return Inertia::render('finance/pos-orders/show', [
            'currency' => CurrencyData::forProperty($property),
            'order' => PosOrderData::fromModel($model, $refunded),
            'folioLinks' => PosOrderFolioLink::query()->where('pos_order_id', $model->id)->with('folio:id,reservation_id')->get()->map(fn (PosOrderFolioLink $link): array => ['id' => $link->id, 'folioId' => $link->folio_id, 'reservationId' => $link->folio?->reservation_id, 'grossAmount' => $link->gross_amount, 'refundedAmount' => (string) ((float) $link->refunded_net + (float) $link->refunded_tax)])->all(),
            'refunds' => PosOrder::query()->where('refund_for_order_id', $model->id)->get(['id', 'order_number', 'grand_total'])->map(fn (PosOrder $refund): array => ['id' => $refund->id, 'orderNumber' => $refund->order_number, 'grandTotal' => $refund->grand_total])->all(),
            'tenders' => collect(PosTenderType::options())->reject(fn (array $option): bool => $option['value'] === PosTenderType::RoomCharge->value)->values()->all(),
            'can' => [
                'refund' => $user->can(PermissionEnum::PosRefund->value),
                'einvoice' => $user->can(PermissionEnum::EInvoiceManage->value),
            ],
        ]);
    }

    /**
     * The receipt as JSON, for the read-only sheet behind a room charge on the folio.
     */
    public function receipt(Property $property, int $order): JsonResponse
    {
        $model = PosOrder::query()->where('property_id', $property->id)->with(PosRegisterController::orderRelations())->findOrFail($order);

        return response()->json(['order' => PosOrderData::fromModel($model)]);
    }

    public function refund(RefundPosOrderRequest $request, Property $property, int $order, RefundPosOrder $refundPosOrder): RedirectResponse
    {
        $original = PosOrder::query()->where('property_id', $property->id)->findOrFail($order);
        $lines = array_values(array_map(fn (array $row): PosRefundLineInputData => new PosRefundLineInputData((int) $row['order_line_id'], (int) $row['quantity'], (bool) ($row['return_stock'] ?? false), (bool) ($row['restore_meal_entitlement'] ?? false)), $request->validated('lines')));
        $result = $refundPosOrder($request->user(), $property, $original, $lines, (string) $request->validated('reason'), (string) ($request->validated('client_uuid') ?? Str::uuid()), $request->user());
        $this->toast($result->refusal, __('Refund recorded.'));

        return back();
    }

    public function changePayment(ChangePosPaymentRequest $request, Property $property, int $order, ChangePosPaymentMethod $changePosPaymentMethod): RedirectResponse
    {
        $original = PosOrder::query()->where('property_id', $property->id)->findOrFail($order);
        $payment = PosOrderPayment::query()->where('pos_order_id', $original->id)->findOrFail((int) $request->validated('payment_id'));

        // The back office has no second approver, so an outlet that needs two people for this must do it at the register.
        if ($original->outlet->require_dual_control) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This outlet needs a second person to approve a tender change. Change it at the register with a manager PIN.')]);

            return back();
        }

        $tender = PosTenderType::from((string) $request->validated('tender_type'));
        $result = $changePosPaymentMethod($request->user(), $property, $original, $payment, $tender, $request->validated('payment_method_id') === null ? null : (int) $request->validated('payment_method_id'), $request->validated('reference'), $request->user());
        $this->toast($result->refusal, __('Tender changed.'));

        return back();
    }

    public function einvoice(SubmitPosEInvoiceRequest $request, Property $property, int $order, SubmitPosWalkInEInvoice $submitPosWalkInEInvoice): RedirectResponse
    {
        $model = PosOrder::query()->where('property_id', $property->id)->findOrFail($order);
        $result = $submitPosWalkInEInvoice($model, $request->user(), $request->validated());

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => __('E-invoice sent to LHDN.')]
            : ['type' => 'error', 'message' => (string) $result->message]);

        return back();
    }
}
