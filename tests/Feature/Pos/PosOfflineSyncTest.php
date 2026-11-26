<?php

use App\Actions\Pos\IssuePosMenuSnapshot;
use App\Actions\Pos\SyncOfflinePosOrders;
use App\Enums\PaymentMethodType;
use App\Enums\PosKitchenStatus;
use App\Enums\PosReconciliationStatus;
use App\Enums\PosTenderType;
use App\Models\PaymentMethod;
use App\Models\PosMenuSnapshot;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOrderPayment;
use App\Models\PosOutletPaymentMethod;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Accounting\PosDailyJournalBuilder;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Pos\PosShiftCashTotals;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosOrderSetup.php';

beforeEach(fn () => Cache::flush());

/** The grand total of one portion of a variant, worked out the way the register does, on a throwaway open check. */
function posOfflineTotal(array $s, $variant, int $quantity = 1): string
{
    $order = posNewOrder($s);
    posAddLine($s, $order, $variant, $quantity);

    return (string) $order->refresh()->grand_total;
}

/** An offline sale of one variant, paid by the given tender. */
function posOfflineSale(array $s, $variant, string $tender = 'cash', ?int $shiftId = null): array
{
    $total = posOfflineTotal($s, $variant);

    return [
        'client_uuid' => (string) Str::uuid(),
        'snapshot_version' => $s['snapshot']['version'],
        'shift_id' => $shiftId ?? $s['shift']->id,
        'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'tenders' => [['tender_type' => $tender, 'amount' => $total, 'tendered_amount' => $tender === 'cash' ? '200.00' : null]],
    ];
}

function posOfflineSetup(): array
{
    $s = posOrderSetup();
    $duitnow = PaymentMethod::factory()->create(['property_id' => $s['property']->id, 'type' => PaymentMethodType::EWallet, 'requires_reference' => false]);
    $row = new PosOutletPaymentMethod(['tender_type' => PosTenderType::DuitNowQr, 'pulse_drawer' => false, 'active' => true, 'sort_order' => 2]);
    $row->property_id = $s['property']->id;
    $row->pos_outlet_id = $s['outlet']->id;
    $row->payment_method_id = $duitnow->id;
    $row->save();

    $s['snapshot'] = app(IssuePosMenuSnapshot::class)($s['property'], $s['outlet']);

    return $s;
}

it('quotes each variant with the service charge and tax the sale is settled at', function () {
    $s = posOfflineSetup();
    $quote = collect($s['snapshot']['categories'])->flatMap(fn (array $category): array => $category['items'])->flatMap(fn (array $item): array => $item['variants'])->firstWhere('id', $s['steak']->id)['quote'];

    expect($quote['total'])->toBe(posOfflineTotal($s, $s['steak']))
        ->and($s['snapshot']['cashRounding'])->toBe($s['outlet']->refresh()->rounding_mode->value);
});

it('does not send a synced offline sale to the kitchen again', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak']);

    app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);

    $statuses = PosOrder::query()->where('client_uuid', $sale['client_uuid'])->sole()->lines()->pluck('kitchen_status')->unique()->values()->all();

    expect($statuses)->toBe([PosKitchenStatus::Served]);
});

it('issues a menu snapshot once per version and keeps it seven days', function () {
    $s = posOfflineSetup();
    app(IssuePosMenuSnapshot::class)($s['property'], $s['outlet']);

    expect(PosMenuSnapshot::query()->count())->toBe(1)
        ->and($s['snapshot']['version'])->toHaveLength(32);

    PosMenuSnapshot::query()->update(['issued_at' => now()->subDays(8)]);
    app(IssuePosMenuSnapshot::class)($s['property'], $s['outlet']);

    expect(PosMenuSnapshot::query()->where('issued_at', '<', now()->subDays(7))->count())->toBe(0);
});

it('replays a cash and a DuitNow sale as settled orders, with the DuitNow waiting for bank-slip reconciliation', function () {
    $s = posOfflineSetup();
    $cash = posOfflineSale($s, $s['steak'], 'cash');
    $duitnow = posOfflineSale($s, $s['pasta'], 'duitnow_qr');

    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$cash, $duitnow]);

    expect($results)->toBe([$cash['client_uuid'] => 'synced', $duitnow['client_uuid'] => 'synced']);

    $order = PosOrder::query()->where('client_uuid', $duitnow['client_uuid'])->sole();
    expect($order->status->value)->toBe('settled')
        ->and($order->order_number)->not->toBeNull()
        ->and($order->snapshot_version)->toBe($s['snapshot']['version'])
        ->and($order->synced_late)->toBeFalse()
        ->and(PosOrderPayment::query()->where('pos_order_id', $order->id)->sole()->reconciliation_status)->toBe(PosReconciliationStatus::PendingVerification);
});

it('replays an order already synced without writing it twice', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak']);
    app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);
    $count = PosOrder::query()->count();

    $again = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);

    expect($again)->toBe([$sale['client_uuid'] => 'replayed'])->and(PosOrder::query()->count())->toBe($count);
});

it('honours a higher snapshot price and logs the drift to the live price', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak']);
    $snapshotTotal = $sale['tenders'][0]['amount'];

    $s['steak']->forceFill(['price' => '80.00'])->save();
    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);

    $order = PosOrder::query()->where('client_uuid', $sale['client_uuid'])->sole();

    expect($results[$sale['client_uuid']])->toBe('synced')
        ->and((string) $order->grand_total)->toBe($snapshotTotal)
        ->and(Activity::query()->where('event', 'pos.offline_price_drift')->exists())->toBeTrue();
});

it('holds a sale whose snapshot price is below the live price until a manager approves it', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak']);

    $s['steak']->forceFill(['price' => '120.00'])->save();
    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);

    expect($results[$sale['client_uuid']])->toBe('price_drift_approval_required')
        ->and(PosOrder::query()->where('client_uuid', $sale['client_uuid'])->count())->toBe(0);
});

it('refuses a menu issued before the open shift, so an earlier menu cannot be replayed', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak']);
    $s['shift']->forceFill(['opened_at' => now()->addMinute()])->save();

    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);

    expect($results[$sale['client_uuid']])->toBe('snapshot_expired');
});

it('refuses a sale from a snapshot older than seven days, and a card tender', function () {
    $s = posOfflineSetup();
    $old = posOfflineSale($s, $s['steak']);
    $card = posOfflineSale($s, $s['pasta'], 'card');
    PosMenuSnapshot::query()->update(['issued_at' => now()->subDays(8)]);

    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$old]);
    expect($results[$old['client_uuid']])->toBe('snapshot_expired')->and(PosOrder::query()->where('client_uuid', $old['client_uuid'])->count())->toBe(0);

    PosMenuSnapshot::query()->update(['issued_at' => now()]);
    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$card]);
    expect($results[$card['client_uuid']])->toBe('tender_not_offline');
});

it('marks a sale from a closed shift as synced late, on the open shift', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak'], 'cash', $s['shift']->id + 1000);

    app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);

    $order = PosOrder::query()->where('client_uuid', $sale['client_uuid'])->sole();
    expect($order->synced_late)->toBeTrue()->and($order->pos_shift_id)->toBe($s['shift']->id)
        ->and(PosOrderLine::query()->where('pos_order_id', $order->id)->count())->toBe(1);
});

it('allows a late-synced sale to use a snapshot issued before the new shift opened, excludes it from the new shift cash, and offsets cash variance in the journal', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak'], 'cash', $s['shift']->id + 1000);
    // Shift B opened after the snapshot was issued during Shift A.
    $s['shift']->forceFill(['opened_at' => now()->addMinute()])->save();

    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale]);

    expect($results[$sale['client_uuid']])->toBe('synced');

    $totals = app(PosShiftCashTotals::class)->for($s['shift']->refresh());
    expect($totals['cash_payments'])->toBe('0.00');

    $date = app(CurrentBusinessDate::class)->get($s['property']);
    $rows = app(PosDailyJournalBuilder::class)->rows($s['property'], $date);
    $varianceCode = TransactionCode::query()->findOrFail($s['outlet']->cash_variance_transaction_code_id)->code;
    expect(collect($rows)->firstWhere('code', $varianceCode))->not->toBeNull();
});

it('lets a manager approve overriding offline price drift and expired snapshots', function () {
    $s = posOfflineSetup();
    $sale = posOfflineSale($s, $s['steak']);
    $s['steak']->forceFill(['price' => '120.00'])->save();
    PosMenuSnapshot::query()->update(['issued_at' => now()->subDays(9)]);

    $results = app(SyncOfflinePosOrders::class)($s['manager'], $s['property'], $s['outlet'], [$sale], $s['manager']);

    expect($results[$sale['client_uuid']])->toBe('synced')
        ->and(Activity::query()->where('event', 'pos.offline_order_synced')->latest('id')->first()?->properties['approved_by_user_id'])->toBe($s['manager']->id);
});

it('lets a staff member sync and get the snapshot only for an outlet of this property', function () {
    $s = posOfflineSetup();
    $cashier = User::factory()->for($s['property'])->withPermissions(['pos.sell', 'pos.shift'])->create();
    $code = $s['property']->code;

    $this->actingAs($cashier)->getJson(route('pos.offline.snapshot', [$code, 'outlet' => $s['outlet']->id]))
        ->assertOk()
        ->assertJsonPath('version', $s['snapshot']['version']);

    $this->actingAs($cashier)->postJson(route('pos.offline.sync', $code), ['outlet_id' => $s['outlet']->id, 'orders' => [posOfflineSale($s, $s['steak'])]])
        ->assertOk()
        ->assertJsonPath('results.'.posOfflineSaleKey($s), 'synced');
});

function posOfflineSaleKey(array $s): string
{
    return (string) PosOrder::query()->latest('id')->value('client_uuid');
}
