<?php

namespace App\Services\Pos;

use App\Enums\PosOrderStatus;
use App\Enums\PosPostingStatus;
use App\Enums\PosShiftStatus;
use App\Models\PosOrder;
use App\Models\PosPosting;
use App\Models\PosShift;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only headline figures above the finance POS lists: receipts, shifts and API postings.
 */
class PosFinanceSummary
{
    /**
     * Figures for the filtered receipts, before pagination. Net sales are settled sales less settled refunds.
     *
     * @param  Builder<PosOrder>  $orders
     * @return array{netSales: string, sales: int, refunds: int, refundTotal: string, voided: int}
     */
    public function receipts(Builder $orders): array
    {
        $row = $orders->toBase()
            ->selectRaw(
                "sum(case when status = ? then case when order_type = 'sale' then grand_total else -grand_total end else 0 end) as net_sales, "
                ."count(case when status = ? and order_type = 'sale' then 1 end) as sales, "
                ."count(case when status = ? and order_type = 'refund' then 1 end) as refunds, "
                ."sum(case when status = ? and order_type = 'refund' then grand_total else 0 end) as refund_total, "
                .'count(case when status = ? then 1 end) as voided',
                [...array_fill(0, 4, PosOrderStatus::Settled->value), PosOrderStatus::Voided->value],
            )
            ->first();

        return [
            'netSales' => $this->money($row->net_sales),
            'sales' => (int) $row->sales,
            'refunds' => (int) $row->refunds,
            'refundTotal' => $this->money($row->refund_total),
            'voided' => (int) $row->voided,
        ];
    }

    /**
     * All shifts of the property. A negative net variance means cash is short.
     *
     * @return array{open: int, closed: int, mismatched: int, netVariance: string}
     */
    public function shifts(Property $property): array
    {
        $row = PosShift::query()->where('property_id', $property->id)->toBase()
            ->selectRaw(
                'count(case when status <> ? then 1 end) as open, '
                .'count(case when status = ? then 1 end) as closed, '
                .'count(case when status = ? and cash_variance <> 0 then 1 end) as mismatched, '
                .'sum(case when status = ? then cash_variance else 0 end) as net_variance',
                array_fill(0, 4, PosShiftStatus::Closed->value),
            )
            ->first();

        return [
            'open' => (int) $row->open,
            'closed' => (int) $row->closed,
            'mismatched' => (int) $row->mismatched,
            'netVariance' => $this->money($row->net_variance),
        ];
    }

    /**
     * Receipts and amount for every posting status, including the empty ones.
     *
     * @return list<array{status: string, label: string, count: int, amount: string}>
     */
    public function postings(Property $property): array
    {
        $rows = PosPosting::query()->where('property_id', $property->id)->toBase()
            ->selectRaw('status, count(*) as count, sum(amount) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return array_map(fn (PosPostingStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($rows->get($status->value)->count ?? 0),
            'amount' => $this->money($rows->get($status->value)->amount ?? null),
        ], PosPostingStatus::cases());
    }

    private function money(int|float|string|null $amount): string
    {
        return (string) BigDecimal::of((string) ($amount ?? '0'))->toScale(2);
    }
}
