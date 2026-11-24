<?php

use App\Actions\Pos\SettlePosOrder;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosStaffPin;
use App\Models\PosTerminalDevice;
use App\Models\PosVoidReason;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/PosOrderSetup.php';

beforeEach(fn () => Cache::flush());

/** A cashier locked to the terminal by PIN, and the manager's PIN for approvals. */
function posRoutesCashier(array $s): array
{
    $cashier = User::factory()->for($s['property'])->withPermissions(['pos.sell', 'pos.shift'])->create();
    PosStaffPin::factory()->withPin('1234')->create(['property_id' => $s['property']->id, 'user_id' => $cashier->id]);
    PosStaffPin::factory()->withPin('123456')->create(['property_id' => $s['property']->id, 'user_id' => $s['manager']->id]);
    $device = PosTerminalDevice::factory()->withToken('tok-'.Str::random(8))->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id]);

    return [$cashier, $device];
}

function posRoutesToast($response): ?array
{
    return $response->getSession()->get('inertia.flash_data.toast');
}

it('opens an order, adds a line and pays in cash, ending on the receipt', function () {
    $s = posOrderSetup();
    $code = $s['property']->code;

    $this->actingAs($s['manager'])->post(route('pos.orders.store', $code), ['register_id' => $s['register']->id, 'pos_dining_option_id' => $s['dineIn']->id])->assertRedirect();
    $order = PosOrder::query()->sole();

    $this->actingAs($s['manager'])->post(route('pos.orders.lines.store', [$code, $order->id]), ['variant_id' => $s['mocha']->id, 'quantity' => 2])->assertRedirect();
    $order->refresh();
    expect($order->lines()->count())->toBe(1)->and($order->grand_total)->not->toBe('0.00');

    $response = $this->actingAs($s['manager'])->post(route('pos.orders.settle', [$code, $order->id]), ['tenders' => [['tender_type' => 'cash', 'amount' => $order->grand_total, 'tendered_amount' => '100.00']]]);
    $response->assertRedirect(route('pos.terminal', ['property' => $code, 'outlet' => $s['outlet']->id, 'register' => $s['register']->id, 'receipt' => $order->id]));

    expect($order->fresh()->status)->toBe(PosOrderStatus::Settled)->and(posRoutesToast($response)['type'])->toBe('success');
});

it('flashes an error toast when an action refuses', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    $response = $this->actingAs($s['manager'])->post(route('pos.orders.settle', [$s['property']->code, $order->id]), ['tenders' => [['tender_type' => 'cash', 'amount' => '1.00']]]);

    expect(posRoutesToast($response)['type'])->toBe('error')->and($order->fresh()->status)->toBe(PosOrderStatus::Open);
});

it('answers 404 for another property and 403 without the permission', function () {
    $s = posOrderSetup();
    $other = posOrderSetup();
    $order = posNewOrder($s);
    $nobody = User::factory()->for($s['property'])->withPermissions(['reports.view'])->create();

    $this->actingAs($other['manager'])->post(route('pos.orders.fire', [$s['property']->code, $order->id]))->assertNotFound();
    $this->actingAs($nobody)->post(route('pos.orders.fire', [$s['property']->code, $order->id]))->assertForbidden();
    $this->actingAs($nobody)->get(route('finance.pos-orders.index', $s['property']->code))->assertForbidden();
    $this->actingAs($s['manager'])->post(route('pos.orders.fire', [$other['property']->code, $order->id]))->assertNotFound();
});

it('acts as the PIN-selected cashier in Terminal Mode, never as the browser manager', function () {
    $s = posOrderSetup();
    [$cashier, $device] = posRoutesCashier($s);
    $code = $s['property']->code;
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    $this->travel(0)->seconds();

    $locked = ['pos_terminal_locked' => true];

    // Nobody has signed in by PIN yet.
    $this->actingAs($s['manager'])->withSession($locked)->post(route('pos.orders.fire', [$code, $order->id]))->assertForbidden();

    $session = $locked + ['pos_active_actor_id' => $cashier->id];
    $this->actingAs($s['manager'])->withSession($session)->post(route('pos.orders.fire', [$code, $order->id]))->assertRedirect();
    expect($line->fresh()->kitchen_status)->toBe(PosKitchenStatus::Fired);

    // A fired line needs a manager: the cashier alone is refused, the manager's PIN lets it through.
    $reason = PosVoidReason::query()->where('property_id', $s['property']->id)->first() ?? PosVoidReason::factory()->create(['property_id' => $s['property']->id]);
    $url = route('pos.orders.lines.void', [$code, $order->id, $line->id]);

    $refused = $this->actingAs($s['manager'])->withSession($session)->post($url, ['void_reason_id' => $reason->id]);
    expect(posRoutesToast($refused)['type'])->toBe('error')->and($line->fresh()->kitchen_status)->toBe(PosKitchenStatus::Fired);

    $wrongPin = $this->actingAs($s['manager'])->withSession($session)->withCookie(PosTerminalDevice::COOKIE, 'x')->post($url, ['void_reason_id' => $reason->id, 'approver_id' => $s['manager']->id, 'approver_pin' => '000000']);
    expect(posRoutesToast($wrongPin)['type'])->toBe('error');
});

it('lets the manager PIN approve a void inline on a registered terminal', function () {
    $s = posOrderSetup();
    [$cashier, $device] = posRoutesCashier($s);
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    $reason = PosVoidReason::factory()->create(['property_id' => $s['property']->id]);
    $session = ['pos_terminal_locked' => true, 'pos_active_actor_id' => $cashier->id];
    $token = 'tok-approve';
    $device->forceFill(['device_token_hash' => hash('sha256', $token)])->save();

    $this->actingAs($s['manager'])->withSession($session)->post(route('pos.orders.fire', [$s['property']->code, $order->id]));
    $response = $this->actingAs($s['manager'])->withSession($session)->withCookie(PosTerminalDevice::COOKIE, $token)
        ->post(route('pos.orders.lines.void', [$s['property']->code, $order->id, $line->id]), ['void_reason_id' => $reason->id, 'approver_id' => $s['manager']->id, 'approver_pin' => '123456']);

    expect(posRoutesToast($response)['type'])->toBe('success')->and($line->fresh()->kitchen_status)->toBe(PosKitchenStatus::Voided);
});

it('refunds from the finance receipt page and checks the line belongs to the order', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash($order->fresh()->grand_total)])->model;
    $line = $order->lines()->sole();

    $response = $this->actingAs($s['manager'])->post(route('finance.pos-orders.refund', [$s['property']->code, $order->id]), ['lines' => [['order_line_id' => $line->id, 'quantity' => 1]], 'reason' => 'Cold dish']);

    expect(posRoutesToast($response)['type'])->toBe('success')->and(PosOrder::query()->where('refund_for_order_id', $order->id)->count())->toBe(1);

    $cashier = User::factory()->for($s['property'])->withPermissions(['pos.view'])->create();
    $this->actingAs($cashier)->post(route('finance.pos-orders.refund', [$s['property']->code, $order->id]), ['lines' => [['order_line_id' => $line->id, 'quantity' => 1]], 'reason' => 'x'])->assertForbidden();
});

it('opens a shift from the launcher route and closes it through the count', function () {
    $s = posOrderSetup();
    $code = $s['property']->code;
    $shift = $s['shift'];

    $this->actingAs($s['manager'])->post(route('pos.shifts.begin-close', [$code, $shift->id]))->assertRedirect();
    expect($shift->fresh()->status->value)->toBe('closing_control');

    $this->actingAs($s['manager'])->post(route('pos.shifts.count', [$code, $shift->id]), ['counted_cash' => '200.00'])->assertRedirect();
    $response = $this->actingAs($s['manager'])->post(route('pos.shifts.close', [$code, $shift->id]));

    $response->assertRedirect(route('pos.index', $code));
    expect($shift->fresh()->status->value)->toBe('closed');

    $open = $this->actingAs($s['manager'])->post(route('pos.shifts.open', $code), ['register_id' => $s['register']->id, 'opening_cash' => '150.00']);
    $open->assertRedirect(route('pos.terminal', ['property' => $code, 'outlet' => $s['outlet']->id, 'register' => $s['register']->id]));
});
