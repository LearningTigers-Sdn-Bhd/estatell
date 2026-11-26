<?php

use App\Actions\Pos\BumpKitchenOrder;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\RefundPosOrder;
use App\Actions\Pos\SavePosKitchenRouting;
use App\Actions\Pos\SavePosKitchenStation;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\UpdateKitchenLineStatus;
use App\Actions\Pos\VoidOrderLine;
use App\Data\Pos\PosRefundLineInputData;
use App\Enums\PosDiningKind;
use App\Enums\PosKitchenStatus;
use App\Enums\PosStationMode;
use App\Models\PosKitchenStation;
use App\Models\PosOrder;
use App\Models\PosVoidReason;
use App\Models\User;
use App\Services\Pos\KitchenDisplay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PosOrderSetup.php';

beforeEach(fn () => Cache::flush());

/** A kitchen with a Hot station (the Food category) and a Bar station that takes the Iced Mocha. */
function posKitchenSetup(): array
{
    $s = posOrderSetup();
    $hot = PosKitchenStation::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id, 'name' => 'Hot Kitchen']);
    $bar = PosKitchenStation::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id, 'name' => 'Bar']);
    app(SavePosKitchenRouting::class)($s['manager'], $s['property'], $s['outlet'], [$s['category']->id => $hot->id], [$s['mocha']->pos_item_id => $bar->id]);

    $kds = User::factory()->for($s['property'])->withPermissions(['pos.kds'])->create();

    return [...$s, 'hot' => $hot, 'bar' => $bar, 'kds' => $kds];
}

it('sends each fired line to the station of its item, or of its category, and tells the screens', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    $steak = posAddLine($s, $order, $s['steak']);
    $mocha = posAddLine($s, $order, $s['mocha']);
    $versionBefore = app(KitchenDisplay::class)->version($s['hot']->id);

    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    expect($steak->refresh()->kitchen_station_id)->toBe($s['hot']->id)
        ->and($mocha->refresh()->kitchen_station_id)->toBe($s['bar']->id)
        ->and(app(KitchenDisplay::class)->version($s['hot']->id))->not->toBe($versionBefore)
        ->and(app(KitchenDisplay::class)->tickets($s['hot'])[0]['lines'])->toHaveCount(1)
        ->and(app(KitchenDisplay::class)->tickets($s['bar'])[0]['lines'][0]['name'])->toBe('Iced Mocha');
});

it('leaves a line with no station off every screen', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['pasta']);
    app(SavePosKitchenRouting::class)($s['manager'], $s['property'], $s['outlet'], [$s['category']->id => null], []);

    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    expect($line->refresh()->kitchen_station_id)->toBeNull()
        ->and(app(KitchenDisplay::class)->tickets($s['hot']))->toBe([]);
});

it('moves a line along the kitchen path and keeps the time of each step', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $update = fn (PosKitchenStatus $to) => app(UpdateKitchenLineStatus::class)($s['property'], $line->refresh(), $to);

    expect($update(PosKitchenStatus::Served)->refusal)->toBe('line_status_invalid')
        ->and($update(PosKitchenStatus::Preparing)->isRefused())->toBeFalse()
        ->and($line->refresh()->preparing_at)->not->toBeNull()
        ->and($update(PosKitchenStatus::Ready)->isRefused())->toBeFalse()
        ->and($update(PosKitchenStatus::Served)->isRefused())->toBeFalse()
        ->and($line->refresh()->served_at)->not->toBeNull()
        ->and($update(PosKitchenStatus::Preparing)->refusal)->toBe('line_status_invalid');
});

it('bumps every fired line of a ticket at a station, and a recall brings the ready ones back', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    $steak = posAddLine($s, $order, $s['steak']);
    $pasta = posAddLine($s, $order, $s['pasta']);
    $mocha = posAddLine($s, $order, $s['mocha']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    $bump = app(BumpKitchenOrder::class);

    expect($bump($s['property'], $s['hot'], $order)->isRefused())->toBeFalse()
        ->and($steak->refresh()->kitchen_status)->toBe(PosKitchenStatus::Ready)
        ->and($pasta->refresh()->kitchen_status)->toBe(PosKitchenStatus::Ready)
        ->and($mocha->refresh()->kitchen_status)->toBe(PosKitchenStatus::Fired)
        ->and($bump($s['property'], $s['hot'], $order)->refusal)->toBe('nothing_to_bump')
        ->and($bump($s['property'], $s['hot'], $order, true)->isRefused())->toBeFalse()
        ->and($steak->refresh()->kitchen_status)->toBe(PosKitchenStatus::Preparing)
        ->and($steak->ready_at)->toBeNull();
});

it('takes a voided fired line off the cooking screen but keeps it as a struck-through docket', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    $steak = posAddLine($s, $order, $s['steak']);
    $pasta = posAddLine($s, $order, $s['pasta']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $reason = PosVoidReason::factory()->create(['property_id' => $s['property']->id, 'active' => true]);
    $versionBefore = app(KitchenDisplay::class)->version($s['hot']->id);

    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $pasta, $reason, null, false, $s['manager']);

    $lines = app(KitchenDisplay::class)->tickets($s['hot'])[0]['lines'];

    expect(app(KitchenDisplay::class)->version($s['hot']->id))->not->toBe($versionBefore)
        ->and(collect($lines)->firstWhere('id', $pasta->id)['voided'])->toBeTrue()
        ->and(collect($lines)->firstWhere('id', $steak->id)['voided'])->toBeFalse();
});

it('shows a station only the dining kinds it is set to take', function () {
    $s = posKitchenSetup();
    $s['hot']->forceFill(['filter_dining_kinds' => PosDiningKind::Takeaway->value])->save();
    $order = posNewOrder($s, $s['dineIn']);
    posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);

    expect(app(KitchenDisplay::class)->tickets($s['hot']))->toBe([]);

    $takeaway = posNewOrder($s, $s['takeaway']);
    posAddLine($s, $takeaway, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $takeaway);

    expect(app(KitchenDisplay::class)->tickets($s['hot']))->toHaveCount(1);
});

it('serves the kitchen screen and its version only to staff with the kitchen permission of this property', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $code = $s['property']->code;

    $this->actingAs($s['kds'])->get(route('pos.kds.show', [$code, $s['hot']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/kds')
            ->where('station.id', $s['hot']->id)
            ->has('tickets', 1)
            ->where('tickets.0.lines.0.status', 'fired'));

    $this->actingAs($s['kds'])->getJson(route('pos.kds.version', [$code, $s['hot']->id]))->assertOk()->assertJsonStructure(['version']);

    $this->actingAs($s['manager'])->get(route('pos.kds.show', [$code, $s['hot']->id]))->assertForbidden();

    $other = posKitchenSetup();
    $this->actingAs($s['kds'])->get(route('pos.kds.show', [$code, $other['hot']->id]))->assertNotFound();
});

it('moves a line from the kitchen screen and bumps a ticket through the routes', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $code = $s['property']->code;

    $this->actingAs($s['kds'])->patch(route('pos.kds.lines.status', [$code, $line->id]), ['status' => 'preparing'])->assertRedirect();
    expect($line->refresh()->kitchen_status)->toBe(PosKitchenStatus::Preparing);

    $this->actingAs($s['kds'])->post(route('pos.kds.orders.bump', [$code, $s['hot']->id, $order->id]))->assertRedirect();
    expect($line->refresh()->kitchen_status)->toBe(PosKitchenStatus::Ready);

    $response = $this->actingAs($s['kds'])->patch(route('pos.kds.lines.status', [$code, $line->id]), ['status' => 'fired']);
    $response->assertSessionHasErrors('status');
});

it('puts a ticket on the pickup board while it cooks and again when all of it is ready', function () {
    $s = posKitchenSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $kitchen = app(KitchenDisplay::class);

    expect($kitchen->pickup($s['outlet'])['preparing'])->toHaveCount(1)
        ->and($kitchen->pickup($s['outlet'])['ready'])->toBe([]);

    app(BumpKitchenOrder::class)($s['property'], $s['hot'], $order);

    expect($kitchen->pickup($s['outlet'])['preparing'])->toBe([])
        ->and($kitchen->pickup($s['outlet'])['ready'])->toHaveCount(1)
        ->and($line->refresh()->kitchen_status)->toBe(PosKitchenStatus::Ready);
});

it('refuses a station name taken in the outlet, thresholds out of order, and a routing to another outlet', function () {
    $s = posKitchenSetup();
    $save = app(SavePosKitchenStation::class);

    expect($save($s['manager'], $s['property'], $s['outlet'], null, ' bar ', PosStationMode::Kds, false, 10, 20)->refusal)->toBe('station_name_taken')
        ->and($save($s['manager'], $s['property'], $s['outlet'], null, 'Cold', PosStationMode::Kds, false, 20, 10)->refusal)->toBe('station_thresholds_invalid');

    $otherOutlet = posKitchenSetup();

    expect(app(SavePosKitchenRouting::class)($s['manager'], $s['property'], $s['outlet'], [$s['category']->id => $otherOutlet['hot']->id], [])->refusal)->toBe('station_invalid');
});

/** A pay-first sale: the steaks are paid for before anything was sent to the kitchen. */
function posPaidKitchenOrder(array $s, int $quantity = 1): PosOrder
{
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak'], $quantity);
    $result = app(SettlePosOrder::class)($s['manager'], $s['property'], $order->refresh(), [posCash((string) $order->grand_total, '1000.00')]);

    expect($result->refusal)->toBeNull();

    return $order->refresh();
}

it('keeps a paid order on the kitchen screens until it is served', function () {
    $s = posKitchenSetup();
    $order = posPaidKitchenOrder($s);
    $line = $order->lines()->sole();
    $kitchen = app(KitchenDisplay::class);

    expect($kitchen->tickets($s['hot']))->toHaveCount(1)
        ->and($kitchen->pickup($s['outlet'])['preparing'])->toHaveCount(1);

    expect(app(BumpKitchenOrder::class)($s['property'], $s['hot'], $order)->refusal)->toBeNull()
        ->and($kitchen->pickup($s['outlet'])['ready'])->toHaveCount(1)
        ->and($kitchen->recallable($s['hot']))->toHaveCount(1);

    expect(app(UpdateKitchenLineStatus::class)($s['property'], $line->refresh(), PosKitchenStatus::Served)->refusal)->toBeNull()
        ->and($kitchen->tickets($s['hot']))->toBe([])
        ->and($kitchen->pickup($s['outlet']))->toBe(['preparing' => [], 'ready' => []]);
});

it('hides paid orders of an earlier business date and refuses to change them', function () {
    $s = posKitchenSetup();
    $order = posPaidKitchenOrder($s);
    $order->forceFill(['business_date' => '2026-10-10', 'settled_at' => now()->subDays(4)])->save();

    expect(app(KitchenDisplay::class)->tickets($s['hot']))->toBe([])
        ->and(app(KitchenDisplay::class)->pickup($s['outlet']))->toBe(['preparing' => [], 'ready' => []])
        ->and(app(BumpKitchenOrder::class)($s['property'], $s['hot'], $order)->refusal)->toBe('order_not_open');
});

it('keeps a just-paid order when the business date has moved on', function () {
    $s = posKitchenSetup();
    $order = posPaidKitchenOrder($s);
    $order->forceFill(['business_date' => '2026-10-13'])->save();

    expect(app(KitchenDisplay::class)->tickets($s['hot']))->toHaveCount(1);
});

it('drops a paid ready order from the pickup board after half an hour but keeps it at the window', function () {
    $s = posKitchenSetup();
    $order = posPaidKitchenOrder($s);
    app(BumpKitchenOrder::class)($s['property'], $s['hot'], $order);
    $before = app(KitchenDisplay::class)->outletVersion($s['outlet']->id);

    $this->travel(31)->minutes();

    expect(app(KitchenDisplay::class)->pickup($s['outlet'])['ready'])->toBe([])
        ->and(app(KitchenDisplay::class)->tickets($s['hot']))->toHaveCount(1)
        ->and(app(KitchenDisplay::class)->outletVersion($s['outlet']->id))->not->toBe($before);
});

it('cooks only what was not refunded, and shows a fully refunded line as void', function () {
    $s = posKitchenSetup();
    $order = posPaidKitchenOrder($s, 2);
    $line = $order->lines()->sole();
    $kitchen = app(KitchenDisplay::class);
    $refund = fn () => app(RefundPosOrder::class)($s['manager'], $s['property'], $order, [new PosRefundLineInputData($line->id, 1, false, false)], 'Changed mind', (string) Str::uuid(), $s['manager']);

    $versionBefore = $kitchen->version($s['hot']->id);
    expect($refund()->refusal)->toBeNull()
        ->and($kitchen->version($s['hot']->id))->not->toBe($versionBefore)
        ->and($kitchen->tickets($s['hot'])[0]['lines'][0])->toMatchArray(['quantity' => '1', 'voided' => false]);

    expect($refund()->refusal)->toBeNull()
        ->and($kitchen->tickets($s['hot'])[0]['lines'][0]['voided'])->toBeTrue()
        ->and($kitchen->pickup($s['outlet']))->toBe(['preparing' => [], 'ready' => []]);

    $this->travel(31)->minutes();

    expect($kitchen->tickets($s['hot']))->toBe([]);
});
