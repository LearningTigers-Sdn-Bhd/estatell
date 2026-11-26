<?php

use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\User;

require_once __DIR__.'/../Support/PosTerminalSetup.php';

it('sums the filtered receipts, leaving voided ones out of sales', function () {
    $setup = nativePosSetup();
    $order = fn (array $attributes) => PosOrder::factory()->create([
        'property_id' => $setup['property']->id,
        'pos_outlet_id' => $setup['outlet']->id,
        'status' => PosOrderStatus::Settled,
        'business_date' => '2026-10-14',
        ...$attributes,
    ]);

    $order(['grand_total' => '100.00']);
    $order(['grand_total' => '20.50', 'order_type' => PosOrderType::Refund]);
    $order(['grand_total' => '999.00', 'status' => PosOrderStatus::Voided]);
    $order(['grand_total' => '40.00', 'business_date' => '2026-10-01']);

    $viewer = User::factory()->for($setup['property'])->withPermissions(['pos.view'])->create();

    $this->actingAs($viewer)
        ->get(route('finance.pos-orders.index', [$setup['property']->code, 'from' => '2026-10-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary', ['netSales' => '79.50', 'sales' => 1, 'refunds' => 1, 'refundTotal' => '20.50', 'voided' => 1]));
});

it('counts open, closed and mismatched shifts of this property only', function () {
    $setup = nativePosSetup();
    $shift = fn () => PosShift::factory()->state(['property_id' => $setup['property']->id, 'pos_outlet_id' => $setup['outlet']->id]);

    $shift()->create();
    $shift()->closed()->create();
    $shift()->closed()->create(['cash_variance' => '-5.00']);
    PosShift::factory()->closed()->create(['cash_variance' => '9.00']);

    $this->actingAs($setup['manager'])
        ->get(route('finance.pos-shifts.index', $setup['property']->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary', ['open' => 1, 'closed' => 2, 'mismatched' => 1, 'netVariance' => '-5.00']));
});

it('filters shifts and their cards by outlet and opening business date', function () {
    $setup = nativePosSetup();
    $shift = fn (array $attributes) => PosShift::factory()->closed()->create(['property_id' => $setup['property']->id, 'pos_outlet_id' => $setup['outlet']->id, ...$attributes]);

    $shift(['opened_business_date' => '2026-10-14', 'cash_variance' => '-2.00']);
    $shift(['opened_business_date' => '2026-10-01', 'cash_variance' => '-5.00']);
    $other = PosOutlet::factory()->create(['property_id' => $setup['property']->id]);
    PosShift::factory()->closed()->create(['property_id' => $setup['property']->id, 'pos_outlet_id' => $other->id, 'opened_business_date' => '2026-10-14']);

    $this->actingAs($setup['manager'])
        ->get(route('finance.pos-shifts.index', [$setup['property']->code, 'outlet' => $setup['outlet']->id, 'from' => '2026-10-10', 'to' => '2026-10-14']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.closed', 1)->where('summary.netVariance', '-2.00')->has('shifts.data', 1));
});
