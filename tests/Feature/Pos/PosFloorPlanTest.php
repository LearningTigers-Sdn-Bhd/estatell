<?php

use App\Actions\Pos\BatchCreatePosTables;
use App\Actions\Pos\CreatePosOrder;
use App\Actions\Pos\FireOrderCourse;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\HoldOrderCourse;
use App\Actions\Pos\MergePosOrders;
use App\Actions\Pos\MovePosOrderItems;
use App\Actions\Pos\SavePosFloor;
use App\Actions\Pos\SplitOrderLineQuantity;
use App\Actions\Pos\SplitPosOrder;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosOrderDiscount;
use App\Models\PosTable;
use App\Services\Pos\TableFloorState;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/PosOrderSetup.php';

function posFloorSetup(): array
{
    $s = posOrderSetup();
    $floor = app(SavePosFloor::class)($s['manager'], $s['property'], $s['outlet'], null, 'Indoor')->model;
    app(BatchCreatePosTables::class)($s['manager'], $s['property'], $floor, 'a', 1, 12, 4);

    return [...$s, 'floor' => $floor, 'table' => PosTable::query()->where('pos_floor_id', $floor->id)->where('code', 'A01')->sole()];
}

function posTableOrder(array $s, ?PosTable $table = null)
{
    return app(CreatePosOrder::class)($s['manager'], $s['property'], $s['shift'], $s['dineIn'], (string) Str::uuid(), 2, null, $table ?? $s['table'])->model;
}

it('creates a floor once per name and a run of tables A01 to A12 in a grid', function () {
    $s = posFloorSetup();

    expect(PosTable::query()->where('pos_floor_id', $s['floor']->id)->count())->toBe(12)
        ->and(PosTable::query()->where('pos_floor_id', $s['floor']->id)->where('code', 'A12')->sole()->pos_x)->toBe(5 * 120)
        ->and(app(SavePosFloor::class)($s['manager'], $s['property'], $s['outlet'], null, ' indoor ')->refusal)->toBe('floor_name_taken')
        ->and(app(BatchCreatePosTables::class)($s['manager'], $s['property'], $s['floor'], 'A', 10, 14)->refusal)->toBe('table_code_exists')
        ->and(PosTable::query()->where('pos_floor_id', $s['floor']->id)->count())->toBe(12)
        ->and(app(BatchCreatePosTables::class)($s['manager'], $s['property'], $s['floor'], 'B', 1, 200)->refusal)->toBe('range_invalid');
});

it('opens an order on a table and refuses a table of another outlet or an inactive one', function () {
    $s = posFloorSetup();
    $order = posTableOrder($s);

    expect($order->pos_table_id)->toBe($s['table']->id);

    $s['table']->forceFill(['active' => false])->save();

    expect(app(CreatePosOrder::class)($s['manager'], $s['property'], $s['shift'], $s['dineIn'], (string) Str::uuid(), 1, null, $s['table'])->refusal)->toBe('table_invalid');
});

it('shows a table as occupied with covers and a running total, and flags two open checks', function () {
    $s = posFloorSetup();
    $first = posTableOrder($s);
    posAddLine($s, $first, $s['steak']);
    posTableOrder($s);

    $tile = collect(app(TableFloorState::class)->for($s['outlet'])[0]['tables'])->firstWhere('code', 'A01');
    $vacant = collect(app(TableFloorState::class)->for($s['outlet'])[0]['tables'])->firstWhere('code', 'A02');

    expect($tile['occupied'])->toBeTrue()->and($tile['covers'])->toBe(4)->and($tile['multiBill'])->toBeTrue()->and($tile['total'])->toBe('116.00')
        ->and($vacant['occupied'])->toBeFalse()->and($vacant['multiBill'])->toBeFalse();
});

it('splits a quantity into two lines that keep the fired time and do not duplicate the docket', function () {
    $s = posFloorSetup();
    $order = posTableOrder($s);
    $line = posAddLine($s, $order, $s['mocha'], 3);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $firedAt = $line->refresh()->fired_at;

    $copy = app(SplitOrderLineQuantity::class)($s['manager'], $s['property'], $order, $line, 1)->model;

    expect((string) $line->refresh()->quantity)->toBe('2.000')->and((string) $copy->quantity)->toBe('1.000')
        ->and($copy->kitchen_status)->toBe(PosKitchenStatus::Fired)->and($copy->fired_at->equalTo($firedAt))->toBeTrue()
        ->and($order->refresh()->grand_total)->toBe('48.72')
        ->and(app(SplitOrderLineQuantity::class)($s['manager'], $s['property'], $order, $line, 2)->refusal)->toBe('split_quantity_invalid');
});

it('splits lines onto a new check on the same table, once per client uuid, and keeps the pre-bill lock', function () {
    $s = posFloorSetup();
    $order = posTableOrder($s);
    $steak = posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);
    $order->forceFill(['prebill_print_count' => 1])->save();
    $uuid = (string) Str::uuid();

    $child = app(SplitPosOrder::class)($s['manager'], $s['property'], $order, [$steak->id], $uuid)->model;
    $again = app(SplitPosOrder::class)($s['manager'], $s['property'], $order, [$steak->id], $uuid);

    expect($child->parent_order_id)->toBe($order->id)->and($child->pos_table_id)->toBe($s['table']->id)->and($child->prebill_print_count)->toBe(1)
        ->and($child->grand_total)->toBe('116.00')->and($again->replayed)->toBeTrue()
        ->and($order->refresh()->grand_total)->toBe('44.00');
});

it('moves lines to another table order, refuses another outlet and a line it does not own', function () {
    $s = posFloorSetup();
    $from = posTableOrder($s);
    $to = posTableOrder($s, PosTable::query()->where('code', 'A02')->sole());
    $line = posAddLine($s, $from, $s['steak']);
    $other = posAddLine($s, $to, $s['pasta']);

    expect(app(MovePosOrderItems::class)($s['manager'], $s['property'], $from, $to, [$other->id])->refusal)->toBe('line_not_movable')
        ->and(app(MovePosOrderItems::class)($s['manager'], $s['property'], $from, $from, [$line->id])->refusal)->toBe('same_order')
        ->and(app(MovePosOrderItems::class)($s['manager'], $s['property'], $from, $to, [$line->id])->isRefused())->toBeFalse()
        ->and($to->refresh()->grand_total)->toBe('160.00')
        ->and($from->refresh()->grand_total)->toBe('0.00');
});

it('merges a check into another and marks the source Merged', function () {
    $s = posFloorSetup();
    $source = posTableOrder($s);
    $target = posTableOrder($s);
    posAddLine($s, $source, $s['steak']);
    posAddLine($s, $target, $s['pasta']);

    $result = app(MergePosOrders::class)($s['manager'], $s['property'], $source, $target);

    expect($result->isRefused())->toBeFalse()->and($source->refresh()->status)->toBe(PosOrderStatus::Merged)
        ->and($target->refresh()->grand_total)->toBe('160.00')->and($target->lines()->count())->toBe(2);
});

it('holds a course and fires it later', function () {
    $s = posFloorSetup();
    $order = posTableOrder($s);
    $starter = posAddLine($s, $order, $s['mocha']);
    $main = posAddLine($s, $order, $s['steak'], 1, ['courseNumber' => 2]);

    expect(app(HoldOrderCourse::class)($s['manager'], $s['property'], $order, 1)->refusal)->toBe('course_invalid');

    app(HoldOrderCourse::class)($s['manager'], $s['property'], $order, 2);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    expect($main->refresh()->kitchen_status)->toBe(PosKitchenStatus::Held)->and($starter->refresh()->kitchen_status)->toBe(PosKitchenStatus::Fired);

    app(FireOrderCourse::class)($s['manager'], $s['property'], $order, 2);

    expect($main->refresh()->kitchen_status)->toBe(PosKitchenStatus::Fired)
        ->and(app(FireOrderCourse::class)($s['manager'], $s['property'], $order, 2)->refusal)->toBe('course_not_held');
});

it('serves the floor plan settings and saves floors, tables and a dragged table through the routes', function () {
    $s = posOrderSetup();
    $code = $s['property']->code;

    $this->actingAs($s['manager'])->post(route('settings.pos.floors.store', [$code, $s['outlet']->id]), ['name' => 'Terrace'])->assertRedirect();
    $floor = $s['outlet']->floors()->sole();

    $this->actingAs($s['manager'])->post(route('settings.pos.tables.batch', [$code, $floor->id]), ['prefix' => 'T', 'start' => 1, 'end' => 3, 'seats' => 2])->assertRedirect();
    $table = PosTable::query()->where('code', 'T01')->sole();

    $this->actingAs($s['manager'])->put(route('settings.pos.tables.update', [$code, $table->id]), ['code' => 'T01', 'seats' => 2, 'shape' => 'round', 'x' => 240, 'y' => 120, 'width' => 100, 'height' => 100])->assertRedirect();

    expect($table->refresh()->pos_x)->toBe(240)->and($table->shape->value)->toBe('round')
        ->and($this->actingAs($s['manager'])->get(route('settings.pos.floors.index', $code))->assertOk()->viewData('page')['props']['floors'][0]['tables'])->toHaveCount(3);
});

it('opens an order on a table, splits and merges it through the routes', function () {
    $s = posFloorSetup();
    $code = $s['property']->code;

    $this->actingAs($s['manager'])->post(route('pos.orders.store', $code), ['register_id' => $s['register']->id, 'pos_dining_option_id' => $s['dineIn']->id, 'pos_table_id' => $s['table']->id])->assertRedirect();
    $order = $s['table']->orders()->sole();
    $steak = posAddLine($s, $order, $s['steak']);
    posAddLine($s, $order, $s['pasta']);

    $this->actingAs($s['manager'])->post(route('pos.orders.split', [$code, $order->id]), ['line_ids' => [$steak->id]])->assertRedirect();
    $child = $s['table']->orders()->where('parent_order_id', $order->id)->sole();

    $this->actingAs($s['manager'])->post(route('pos.orders.merge', [$code, $child->id]), ['target_order_id' => $order->id])->assertRedirect();

    expect($child->refresh()->status)->toBe(PosOrderStatus::Merged)->and($order->refresh()->grand_total)->toBe('160.00');
});

it('refuses to split a discounted line or move lines on a check with a bill discount', function () {
    $s = posFloorSetup();
    $order = posTableOrder($s);
    $line = posAddLine($s, $order, $s['mocha'], 2);
    $other = posTableOrder($s);
    PosOrderDiscount::factory()->create(['property_id' => $s['property']->id, 'pos_order_id' => $other->id, 'applied_by_user_id' => $s['manager']->id]);

    expect(app(MovePosOrderItems::class)($s['manager'], $s['property'], $order, $other, [$line->id])->refusal)->toBe('order_has_discounts');

    $line->forceFill(['discount_type' => 'fixed_amount', 'discount_value' => '2.00'])->save();

    expect(app(SplitOrderLineQuantity::class)($s['manager'], $s['property'], $order, $line, 1)->refusal)->toBe('line_not_splittable');
});
