<?php

namespace App\Services\Pos;

use App\Enums\PosOrderStatus;
use App\Models\PosTimeClock;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Read-only sales, audit, included-meal redemption, and staff time-clock aggregates for the POS Sales report.
 */
class PosSalesReport
{
    /**
     * @return array{
     *     byOutlet: list<array{name: string, total: string, orders: int, discounts: string}>,
     *     byCategory: list<array{name: string, net: string, quantity: string}>,
     *     audit: array{voidedOrders: int, voidedLines: int, refunds: int},
     *     redeemedMeals: list<array{outletName: string, mealPeriodName: string, portions: string, grossValue: string}>,
     *     timeClocks: list<array{id: int, staffName: string, outletName: string, clockedInAt: string, clockedOutAt: ?string, minutes: ?int}>
     * }
     */
    public function forRange(Property $property, string $from, string $to): array
    {
        $orders = fn () => DB::table('pos_orders')
            ->where('pos_orders.property_id', $property->id)
            ->whereBetween('pos_orders.business_date', [$from, $to]);

        $byOutlet = $orders()
            ->where('pos_orders.status', PosOrderStatus::Settled->value)
            ->join('pos_outlets', 'pos_outlets.id', '=', 'pos_orders.pos_outlet_id')
            ->selectRaw("pos_outlets.name as name, sum(case when order_type = 'sale' then grand_total else -grand_total end) as total, sum(case when order_type = 'sale' then 1 else 0 end) as orders, sum(case when order_type = 'sale' then discount_total else -discount_total end) as discounts")
            ->groupBy('pos_outlets.name')
            ->orderBy('pos_outlets.name')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'total' => number_format((float) $row->total, 2, '.', ''),
                'orders' => (int) $row->orders,
                'discounts' => number_format((float) $row->discounts, 2, '.', ''),
            ])
            ->all();

        $byCategory = DB::table('pos_order_lines')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_lines.pos_order_id')
            ->join('pos_item_variants', 'pos_item_variants.id', '=', 'pos_order_lines.pos_item_variant_id')
            ->join('pos_items', 'pos_items.id', '=', 'pos_item_variants.pos_item_id')
            ->join('pos_categories', 'pos_categories.id', '=', 'pos_items.pos_category_id')
            ->where('pos_orders.property_id', $property->id)
            ->whereBetween('pos_orders.business_date', [$from, $to])
            ->where('pos_orders.status', PosOrderStatus::Settled->value)
            ->where('pos_order_lines.kitchen_status', '<>', 'voided')
            ->selectRaw("pos_categories.name as name, sum(case when pos_orders.order_type = 'sale' then pos_order_lines.net_amount else -pos_order_lines.net_amount end) as net, sum(case when pos_orders.order_type = 'sale' then pos_order_lines.quantity else -pos_order_lines.quantity end) as quantity")
            ->groupBy('pos_categories.name')
            ->orderByDesc('net')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'net' => number_format((float) $row->net, 2, '.', ''),
                'quantity' => (string) (float) $row->quantity,
            ])
            ->all();

        $voidedLines = DB::table('pos_order_lines')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_lines.pos_order_id')
            ->where('pos_orders.property_id', $property->id)
            ->whereRaw('coalesce(pos_orders.business_date, date(pos_orders.opened_at)) between ? and ?', [$from, $to])
            ->where('pos_order_lines.kitchen_status', 'voided')
            ->count();

        $redeemedMeals = DB::table('pos_order_lines')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_lines.pos_order_id')
            ->join('pos_outlets', 'pos_outlets.id', '=', 'pos_orders.pos_outlet_id')
            ->leftJoin('pos_item_variants', 'pos_item_variants.id', '=', 'pos_order_lines.pos_item_variant_id')
            ->leftJoin('pos_items', 'pos_items.id', '=', 'pos_item_variants.pos_item_id')
            ->leftJoin('meal_periods', 'meal_periods.id', '=', 'pos_items.meal_period_id')
            ->where('pos_orders.property_id', $property->id)
            ->whereBetween('pos_orders.business_date', [$from, $to])
            ->where('pos_orders.status', PosOrderStatus::Settled->value)
            ->where('pos_order_lines.kitchen_status', '<>', 'voided')
            ->where('pos_order_lines.is_entitlement_covered', true)
            ->selectRaw("pos_outlets.name as outlet_name, coalesce(meal_periods.name, 'Included meal') as meal_period_name, sum(case when pos_orders.order_type = 'sale' then pos_order_lines.quantity else -pos_order_lines.quantity end) as portions, sum(case when pos_orders.order_type = 'sale' then pos_order_lines.line_gross else -pos_order_lines.line_gross end) as gross_value")
            ->groupBy('pos_outlets.name', 'meal_periods.name')
            ->orderBy('pos_outlets.name')
            ->get()
            ->map(fn (object $row): array => [
                'outletName' => (string) $row->outlet_name,
                'mealPeriodName' => (string) $row->meal_period_name,
                'portions' => (string) (float) $row->portions,
                'grossValue' => number_format((float) $row->gross_value, 2, '.', ''),
            ])
            ->all();

        $timeClocks = PosTimeClock::query()
            ->where('property_id', $property->id)
            ->whereDate('clocked_in_at', '>=', $from)
            ->whereDate('clocked_in_at', '<=', $to)
            ->with(['user:id,name', 'outlet:id,name'])
            ->orderByDesc('clocked_in_at')
            ->limit(100)
            ->get()
            ->map(fn (PosTimeClock $row): array => [
                'id' => $row->id,
                'staffName' => $row->user->name,
                'outletName' => $row->outlet->name,
                'clockedInAt' => $row->clocked_in_at->toIso8601String(),
                'clockedOutAt' => $row->clocked_out_at?->toIso8601String(),
                'minutes' => $row->clocked_out_at === null ? null : (int) $row->clocked_in_at->diffInMinutes($row->clocked_out_at),
            ])
            ->values()
            ->all();

        return [
            'byOutlet' => array_values($byOutlet),
            'byCategory' => array_values($byCategory),
            'audit' => [
                'voidedOrders' => $orders()->where('pos_orders.status', PosOrderStatus::Voided->value)->count(),
                'voidedLines' => $voidedLines,
                'refunds' => $orders()->where('pos_orders.order_type', 'refund')->count(),
            ],
            'redeemedMeals' => array_values($redeemedMeals),
            'timeClocks' => array_values($timeClocks),
        ];
    }
}
