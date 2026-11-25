<?php

use App\Actions\Pos\ApplyPosDiscount;
use App\Actions\Pos\BatchCreatePosTables;
use App\Actions\Pos\FireOrderLines;
use App\Actions\Pos\IssueTableQrSession;
use App\Actions\Pos\PosActionResult;
use App\Actions\Pos\SavePosFloor;
use App\Actions\Pos\SavePosKitchenRouting;
use App\Actions\Pos\SavePosOutlet;
use App\Actions\Pos\SavePosOutletSettings;
use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\VoidPosOrder;
use App\Data\Pos\PosOutletData;
use App\Data\Pos\PosTenderInputData;
use App\Enums\PosDiscountType;
use App\Enums\PosOrderStatus;
use App\Enums\PosTenderType;
use App\Enums\TransactionKind;
use App\Models\PosKitchenStation;
use App\Models\PosOrderPayment;
use App\Models\PosTable;
use App\Models\PosVoidReason;
use App\Models\TransactionCode;
use Inertia\Support\SessionKey;

require_once __DIR__.'/../Support/PosTerminalSetup.php';
require_once __DIR__.'/../Support/PosSetup.php';
require_once __DIR__.'/../Support/PosOrderSetup.php';

it('gives a property the common void reasons once when its first outlet is turned on', function () {
    $setup = nativePosSetup();
    PosVoidReason::query()->where('property_id', $setup['property']->id)->delete();

    app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Lobby Cafe', 'cafe', null, true, true));
    $first = PosVoidReason::query()->where('property_id', $setup['property']->id)->orderBy('sort_order')->get();

    app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Pool Bar', 'pool', null, true, true));

    expect($first->pluck('label')->all())->toBe(['Wrong item entered', 'Out of stock', 'Kitchen error', 'Guest changed mind'])
        ->and($first->firstWhere('label', 'Guest changed mind')->requires_manager_pin)->toBeTrue()
        ->and($first->firstWhere('label', 'Kitchen error')->requires_manager_pin)->toBeFalse()
        ->and(PosVoidReason::query()->where('property_id', $setup['property']->id)->count())->toBe(4);
});

it('only takes charge codes for the service charge and packaging fee accounts', function () {
    $setup = nativePosSetup();
    $property = $setup['property'];
    $outlet = $setup['outlet'];
    $outlet->forceFill(['pos_enabled' => true])->save();
    $tax = TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Tax]);
    $charge = TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Charge]);
    $save = fn (array $values): PosActionResult => app(SavePosOutletSettings::class)($setup['manager'], $property, $outlet, $values);

    expect($save(['service_charge_transaction_code_id' => $tax->id])->refusal)->toBe('charge_code_required')
        ->and($save(['packaging_transaction_code_id' => $tax->id])->refusal)->toBe('charge_code_required')
        ->and($save(['service_charge_transaction_code_id' => $charge->id, 'packaging_transaction_code_id' => $charge->id])->isRefused())->toBeFalse()
        ->and($outlet->fresh()->service_charge_transaction_code_id)->toBe($charge->id)
        // The accounts that only reach the daily journal may be any code.
        ->and($save(['rounding_transaction_code_id' => $tax->id])->isRefused())->toBeFalse();
});

it('settles a check with nothing left to pay without a tender', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, PosDiscountType::FixedPercent, '100');

    $result = app(SettlePosOrder::class)($s['manager'], $s['property'], $order->refresh(), []);
    $settled = $result->model;

    expect($result->isRefused())->toBeFalse()
        ->and($settled->status)->toBe(PosOrderStatus::Settled)
        ->and($settled->grand_total)->toBe('0.00')
        ->and($settled->order_number)->not->toBeNull()
        ->and($settled->settled_on_folio)->toBeFalse()
        ->and(PosOrderPayment::query()->count())->toBe(0);
});

it('drops a zero tender on a check with nothing left to pay', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(ApplyPosDiscount::class)($s['manager'], $s['property'], $order, PosDiscountType::FixedPercent, '100');

    $result = app(SettlePosOrder::class)($s['manager'], $s['property'], $order->refresh(), [new PosTenderInputData(PosTenderType::Cash, '0.00')]);

    expect($result->isRefused())->toBeFalse()
        ->and(PosOrderPayment::query()->count())->toBe(0);
});

it('still refuses a zero tender on a check that has something to pay', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);

    $result = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [new PosTenderInputData(PosTenderType::Cash, '0.00')]);

    expect($result->refusal)->toBe('tender_invalid');
});

it('discards a check with nothing on it without a reason and still asks a reason for a check with lines', function () {
    $s = posOrderSetup();
    $empty = posNewOrder($s);
    $filled = posNewOrder($s);
    posAddLine($s, $filled, $s['steak']);

    $discarded = app(VoidPosOrder::class)($s['manager'], $s['property'], $empty, null);
    $refused = app(VoidPosOrder::class)($s['manager'], $s['property'], $filled, null);

    expect($discarded->isRefused())->toBeFalse()
        ->and($empty->fresh()->status)->toBe(PosOrderStatus::Voided)
        ->and($empty->fresh()->void_reason_id)->toBeNull()
        ->and($refused->refusal)->toBe('void_reason_invalid')
        ->and($filled->fresh()->status)->toBe(PosOrderStatus::Open);
});

it('routes lines sent before a kitchen station existed once the routing is saved', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    app(FireOrderLines::class)($s['manager'], $s['property'], $order);
    $hot = PosKitchenStation::factory()->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id, 'name' => 'Hot Kitchen']);

    expect($line->fresh()->kitchen_station_id)->toBeNull();

    app(SavePosKitchenRouting::class)($s['manager'], $s['property'], $s['outlet'], [$s['category']->id => $hot->id], []);

    expect($line->fresh()->kitchen_station_id)->toBe($hot->id);
});

it('shows a guest the table QR total with the service charge of a dine-in check', function () {
    $s = posOrderSetup();
    $floor = app(SavePosFloor::class)($s['manager'], $s['property'], $s['outlet'], null, 'Indoor')->model;
    app(BatchCreatePosTables::class)($s['manager'], $s['property'], $floor, 'a', 1, 4, 4);
    $table = PosTable::query()->where('pos_floor_id', $floor->id)->where('code', 'A01')->sole();
    $link = app(IssueTableQrSession::class)($s['manager'], $s['property'], $table)->model;

    $this->get(route('qr.table.show', $link->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('serviceChargeRate', '10.00'));
});

it('keeps the cashier toast in the session when the customer display reloads', function () {
    $s = posOrderSetup();
    $toast = ['toast' => ['type' => 'success', 'message' => 'Added.']];

    $this->actingAs($s['manager'])
        ->withSession([SessionKey::FLASH_DATA => $toast])
        ->get(route('pos.cfd.show', [$s['property']->code, $s['register']->id]))
        ->assertOk();

    expect(session(SessionKey::FLASH_DATA))->toBe($toast);
});

it('names a service charge code that is not a charge when a room charge cannot post', function () {
    $s = posOrderSetup();
    $tax = TransactionCode::factory()->for($s['property'])->create(['kind' => TransactionKind::Tax]);
    $s['outlet']->forceFill(['service_charge_transaction_code_id' => $tax->id])->save();
    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);

    $result = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [new PosTenderInputData(PosTenderType::RoomCharge, '116.00', reservationUnitId: $s['unit']->id)], guestSigned: true);

    expect($result->refusal)->toBe('charge_code_not_chargeable')
        ->and(PosOrderPayment::query()->count())->toBe(0);
});
