<?php

namespace App\Services\Pos;

use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Enums\PosPaymentStatus;
use App\Models\PosCashMovement;
use App\Models\PosOrder;
use App\Models\PosShift;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The Z-report of a shift as plain data, frozen into `pos_shifts.z_report_snapshot` when the shift closes so the report
 * never changes afterwards: cash reconciliation, sales and refund totals, tenders, voids and every cash movement.
 * Call it after the shift's cash totals are final. It reads only.
 */
class PosZReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(PosShift $shift): array
    {
        $shift->loadMissing(['outlet', 'register', 'openedBy', 'closedBy']);

        $orders = fn (PosOrderType $type, PosOrderStatus $status) => PosOrder::query()
            ->where('pos_shift_id', $shift->id)
            ->where('order_type', $type->value)
            ->where('status', $status->value);

        $sum = fn ($query, string $column): string => BigDecimal::of((string) $query->sum($column))->toScale(2)->__toString();

        $tenders = DB::table('pos_order_payments')
            ->where('pos_shift_id', $shift->id)
            ->where('status', PosPaymentStatus::Captured->value)
            ->selectRaw('tender_type, direction, count(*) as payment_count, sum(amount) as amount_total, sum(rounding_adjustment) as rounding_total')
            ->groupBy('tender_type', 'direction')
            ->orderBy('tender_type')
            ->orderBy('direction')
            ->get()
            ->map(fn (object $row): array => [
                'tender_type' => $row->tender_type,
                'direction' => $row->direction,
                'payments' => (int) $row->payment_count,
                'amount' => BigDecimal::of((string) $row->amount_total)->toScale(2)->__toString(),
                'rounding_adjustment' => BigDecimal::of((string) $row->rounding_total)->toScale(2)->__toString(),
            ])->values()->all();

        return [
            'shift_number' => $shift->shift_number,
            'outlet' => $shift->outlet->name,
            'register' => $shift->register->name,
            'opened_by' => $shift->openedBy->name,
            'closed_by' => $shift->closedBy?->name,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'opened_business_date' => $shift->opened_business_date->toDateString(),
            'closed_business_date' => $shift->closed_business_date?->toDateString(),
            'cash' => [
                'opening_cash' => $shift->opening_cash,
                'cash_payments' => $shift->cash_payments,
                'cash_refunds' => $shift->cash_refunds,
                'paid_in' => $shift->paid_in,
                'paid_out' => $shift->paid_out,
                'expected_cash' => $shift->expected_cash,
                'counted_cash' => $shift->counted_cash,
                'cash_variance' => $shift->cash_variance,
                'recount_count' => $shift->recount_count,
            ],
            'sales' => [
                'orders' => $orders(PosOrderType::Sale, PosOrderStatus::Settled)->count(),
                'subtotal' => $sum($orders(PosOrderType::Sale, PosOrderStatus::Settled), 'subtotal'),
                'discounts' => $sum($orders(PosOrderType::Sale, PosOrderStatus::Settled), 'discount_total'),
                'service_charge' => $sum($orders(PosOrderType::Sale, PosOrderStatus::Settled), 'service_charge_total'),
                'tax' => $sum($orders(PosOrderType::Sale, PosOrderStatus::Settled), 'tax_total'),
                'grand_total' => $sum($orders(PosOrderType::Sale, PosOrderStatus::Settled), 'grand_total'),
            ],
            'refunds' => [
                'orders' => $orders(PosOrderType::Refund, PosOrderStatus::Settled)->count(),
                'grand_total' => $sum($orders(PosOrderType::Refund, PosOrderStatus::Settled), 'grand_total'),
            ],
            'voided_orders' => PosOrder::query()->where('pos_shift_id', $shift->id)->where('status', PosOrderStatus::Voided->value)->count(),
            'tenders' => $tenders,
            'cash_movements' => PosCashMovement::query()->where('pos_shift_id', $shift->id)->orderBy('id')->get()
                ->map(fn (PosCashMovement $movement): array => [
                    'kind' => $movement->kind->value,
                    'amount' => $movement->amount,
                    'reason' => $movement->reason,
                    'business_date' => $movement->business_date->toDateString(),
                ])->all(),
            'note' => $shift->closing_note,
        ];
    }
}
