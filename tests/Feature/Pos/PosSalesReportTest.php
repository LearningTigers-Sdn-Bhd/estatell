<?php

use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Models\PosOrder;

require_once __DIR__.'/../Support/PosTerminalSetup.php';

it('shows headline figures net of refunds and one row per day of the range', function () {
    $setup = nativePosSetup();
    $order = fn (array $attributes) => PosOrder::factory()->create([
        'property_id' => $setup['property']->id,
        'pos_outlet_id' => $setup['outlet']->id,
        'status' => PosOrderStatus::Settled,
        ...$attributes,
    ]);

    $order(['business_date' => '2026-10-12', 'grand_total' => '100.00', 'discount_total' => '5.00']);
    $order(['business_date' => '2026-10-14', 'grand_total' => '50.01']);
    $order(['business_date' => '2026-10-14', 'grand_total' => '20.00', 'order_type' => PosOrderType::Refund]);
    $order(['business_date' => '2026-10-14', 'grand_total' => '999.00', 'status' => PosOrderStatus::Voided]);

    $this->actingAs($setup['manager'])
        ->get(route('reports.pos-sales', [$setup['property']->code, 'from' => '2026-10-12', 'to' => '2026-10-14']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary', ['total' => '130.01', 'orders' => 2, 'averageOrder' => '65.01', 'discounts' => '5.00'])
            ->where('daily', [
                ['date' => '2026-10-12', 'total' => '100.00', 'orders' => 1],
                ['date' => '2026-10-13', 'total' => '0.00', 'orders' => 0],
                ['date' => '2026-10-14', 'total' => '30.01', 'orders' => 1],
            ]));
});

it('has no average order and no daily rows without sales', function () {
    $setup = nativePosSetup();

    $this->actingAs($setup['manager'])
        ->get(route('reports.pos-sales', $setup['property']->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.averageOrder', null)->where('summary.total', '0.00')->where('daily', []));
});

it('rejects a date that is not a date and falls back to the defaults for an empty one', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['manager'])->get(route('reports.pos-sales', [$code, 'from' => 'nope']))->assertSessionHasErrors('from');
    $this->actingAs($setup['manager'])->get(route('reports.pos-sales', [$code]).'?from=&to=')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters', ['from' => '2026-10-01', 'to' => '2026-10-14']));
});
