<?php

namespace App\Http\Controllers\Pos;

use App\Data\Property\CurrencyData;
use App\Enums\PosOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Walk-in and room-charged POS sales for a date range: by outlet and by category, with voids and discounts to audit.
 */
class PosSalesReportController extends Controller
{
    public function __invoke(Request $request, Property $property): Response
    {
        $from = (string) $request->query('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->query('to', now()->toDateString());

        $orders = fn () => DB::table('pos_orders')->where('property_id', $property->id)->whereBetween('business_date', [$from, $to]);

        return Inertia::render('reports/pos-sales', [
            'currency' => CurrencyData::forProperty($property),
            'filters' => ['from' => $from, 'to' => $to],
            'byOutlet' => $orders()->where('status', PosOrderStatus::Settled->value)->join('pos_outlets', 'pos_outlets.id', '=', 'pos_orders.pos_outlet_id')
                ->selectRaw("pos_outlets.name as name, sum(case when order_type = 'sale' then grand_total else -grand_total end) as total, sum(case when order_type = 'sale' then 1 else 0 end) as orders, sum(discount_total) as discounts")
                ->groupBy('pos_outlets.name')->orderBy('pos_outlets.name')->get()->map(fn (object $row): array => ['name' => $row->name, 'total' => number_format((float) $row->total, 2, '.', ''), 'orders' => (int) $row->orders, 'discounts' => number_format((float) $row->discounts, 2, '.', '')])->all(),
            'byCategory' => DB::table('pos_order_lines')->join('pos_orders', 'pos_orders.id', '=', 'pos_order_lines.pos_order_id')
                ->join('pos_item_variants', 'pos_item_variants.id', '=', 'pos_order_lines.pos_item_variant_id')->join('pos_items', 'pos_items.id', '=', 'pos_item_variants.pos_item_id')->join('pos_categories', 'pos_categories.id', '=', 'pos_items.pos_category_id')
                ->where('pos_orders.property_id', $property->id)->whereBetween('pos_orders.business_date', [$from, $to])->where('pos_orders.status', PosOrderStatus::Settled->value)->where('pos_order_lines.kitchen_status', '<>', 'voided')
                ->selectRaw("pos_categories.name as name, sum(case when pos_orders.order_type = 'sale' then pos_order_lines.net_amount else -pos_order_lines.net_amount end) as net, sum(case when pos_orders.order_type = 'sale' then pos_order_lines.quantity else -pos_order_lines.quantity end) as quantity")
                ->groupBy('pos_categories.name')->orderByDesc('net')->get()->map(fn (object $row): array => ['name' => $row->name, 'net' => number_format((float) $row->net, 2, '.', ''), 'quantity' => (string) (float) $row->quantity])->all(),
            'audit' => [
                'voidedOrders' => $orders()->where('status', PosOrderStatus::Voided->value)->count(),
                'voidedLines' => DB::table('pos_order_lines')->join('pos_orders', 'pos_orders.id', '=', 'pos_order_lines.pos_order_id')->where('pos_orders.property_id', $property->id)->whereBetween('pos_orders.opened_at', [$from.' 00:00:00', $to.' 23:59:59'])->where('pos_order_lines.kitchen_status', 'voided')->count(),
                'refunds' => $orders()->where('order_type', 'refund')->count(),
            ],
        ]);
    }
}
