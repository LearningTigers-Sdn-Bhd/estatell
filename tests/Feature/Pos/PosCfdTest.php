<?php

use App\Actions\Pos\SettlePosOrder;
use App\Actions\Pos\UpdateRegisterDisplayState;
use App\Actions\Pos\VoidOrderLine;
use App\Data\Pos\PosTenderInputData;
use App\Enums\EInvoiceEnvironment;
use App\Enums\PosTenderType;
use App\Features\EInvoicing;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosVoidReason;
use App\Models\PropertyEInvoiceSetting;
use App\Models\User;
use App\Services\Pos\CustomerDisplay;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosOrderSetup.php';

beforeEach(fn () => Cache::flush());
afterEach(fn () => Carbon::setTestNow());

/** A register pointed at an open order with one Steak, and the display's state for it. */
function posCfdActive(array $s): array
{
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    app(UpdateRegisterDisplayState::class)($s['property'], $s['register'], $order->id, false);

    return [$order, app(CustomerDisplay::class)->stateFor($s['property'], $s['register']->refresh())];
}

it('forbids staff without pos.sell and 404s another property register', function () {
    $s = posOrderSetup();
    $nobody = User::factory()->for($s['property'])->withPermissions(['pos.view'])->create();
    $stranger = posOrderSetup();
    $code = $s['property']->code;

    $this->actingAs($nobody)->get(route('pos.cfd.show', [$code, $s['register']->id]))->assertForbidden();
    $this->actingAs($nobody)->get(route('pos.cfd.version', [$code, $s['register']->id]))->assertForbidden();
    $this->actingAs($nobody)->post(route('pos.cfd.state', [$code, $s['register']->id]), [])->assertForbidden();

    $this->actingAs($s['manager'])->get(route('pos.cfd.show', [$code, $stranger['register']->id]))->assertNotFound();
});

it('shows idle for a register with no active order', function () {
    $s = posOrderSetup();

    $page = $this->actingAs($s['manager'])->get(route('pos.cfd.show', [$s['property']->code, $s['register']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia->component('pos/cfd')->where('state.mode', 'idle'));
    $props = $page->viewData('page')['props'];

    expect($props['state']['mode'])->toBe('idle')
        ->and($props['state']['outletName'])->toBe($s['outlet']->name)
        ->and($props['state']['order'])->toBeNull();
});

it('shows the open check with server totals and no voided lines, notes or cost', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $order->forceFill(['order_note' => 'VIP_SECRET_NOTE'])->save();
    $s['steak']->forceFill(['cost' => '43.21'])->save();
    $s['steak']->item->forceFill(['internal_name' => 'KITCHEN_SECRET_ALIAS'])->save();
    posAddLine($s, $order, $s['steak']);
    $voided = posAddLine($s, $order, $s['mocha']);
    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $voided, PosVoidReason::factory()->create(['property_id' => $s['property']->id, 'requires_manager_pin' => false]));
    app(UpdateRegisterDisplayState::class)($s['property'], $s['register'], $order->id, false);
    $state = app(CustomerDisplay::class)->stateFor($s['property'], $s['register']->refresh());

    expect($state['mode'])->toBe('active')
        ->and($state['order']['grandTotal'])->toBe($order->fresh()->grand_total)
        ->and($state['order']['lines'])->toHaveCount(1)
        ->and($state['order']['lines'][0]['itemName'])->toBe('Steak')
        ->and(json_encode($state))
        ->not->toContain('VIP_SECRET_NOTE')
        ->not->toContain('KITCHEN_SECRET_ALIAS')
        ->not->toContain('43.21')
        ->not->toContain('unit_cost')
        ->not->toContain('order_note')
        ->not->toContain('internal_name');
});

it('falls back to idle when every line on an open order has been voided', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    $line = posAddLine($s, $order, $s['steak']);
    app(UpdateRegisterDisplayState::class)($s['property'], $s['register'], $order->id, false);
    app(VoidOrderLine::class)($s['manager'], $s['property'], $order, $line, PosVoidReason::factory()->create(['property_id' => $s['property']->id, 'requires_manager_pin' => false]));

    $state = app(CustomerDisplay::class)->stateFor($s['property'], $s['register']->refresh());

    expect($state['mode'])->toBe('idle')->and($state['order'])->toBeNull();
});

it('switches to checkout on the state endpoint and bumps the version', function () {
    $s = posOrderSetup();
    [$order] = posCfdActive($s);
    $before = app(CustomerDisplay::class)->version($s['register']->id);

    $response = $this->actingAs($s['manager'])->post(route('pos.cfd.state', [$s['property']->code, $s['register']->id]), [
        'order_id' => $order->id, 'checkout_active' => true,
    ])->assertOk();

    expect($response->json('version'))->not->toBe($before)
        ->and(app(CustomerDisplay::class)->stateFor($s['property'], $s['register']->refresh())['mode'])->toBe('checkout');
});

it('shows the settled check with change and a walk-in claim QR, and expires to idle after 30 seconds', function () {
    $s = posOrderSetup();
    Feature::for($s['property'])->activate(EInvoicing::class);
    PropertyEInvoiceSetting::factory()->for($s['property'])->create([
        'enabled' => true, 'api_environment' => EInvoiceEnvironment::Mock,
        'client_id' => 'mock-client-id', 'client_secret' => 'mock-client-secret',
        'supplier_name' => 'Mock Grand Hotel Sdn Bhd', 'supplier_tin' => 'C1234567890',
        'supplier_sst_registration' => 'W10-1808-12345678', 'supplier_msic_code' => '55101',
        'supplier_business_description' => 'Hotel operations', 'supplier_email' => 'accounts@hotel.my',
        'supplier_city' => 'Kuala Lumpur',
    ]);
    [$order] = posCfdActive($s);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('116.00', '120.00')])->model;

    $state = app(CustomerDisplay::class)->stateFor($s['property'], $s['register']->refresh());

    expect($state['mode'])->toBe('settled')
        ->and($state['order']['totalChange'])->toBe('4.00')
        ->and($state['order']['claimQrSvg'])->toStartWith('<?xml')
        ->and($state['order']['payments'][0]['tender'])->toBe('Cash');

    $this->travel(31)->seconds();

    expect(app(CustomerDisplay::class)->stateFor($s['property'], $s['register']->refresh())['mode'])->toBe('idle');
});

it('never exposes a claim QR for a room-only sale', function () {
    $s = posOrderSetup();
    Feature::for($s['property'])->activate(EInvoicing::class);
    PropertyEInvoiceSetting::factory()->for($s['property'])->create(['enabled' => true]);

    $order = posNewOrder($s, null, $s['unit']);
    posAddLine($s, $order, $s['steak']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [
        new PosTenderInputData(PosTenderType::RoomCharge, '116.00', reservationUnitId: $s['unit']->id),
    ], true)->model;

    $state = app(CustomerDisplay::class)->stateFor($s['property'], PosRegister::query()->findOrFail($s['register']->id));

    expect($state['mode'])->toBe('settled')->and($state['order']['claimQrSvg'])->toBeNull();
});

it('keeps the settled check on screen when the cashier clears the register within the thank-you window', function () {
    $s = posOrderSetup();
    $order = posNewOrder($s);
    posAddLine($s, $order, $s['steak']);
    $order = app(SettlePosOrder::class)($s['manager'], $s['property'], $order, [posCash('116.00')])->model;

    $this->actingAs($s['manager'])->get(route('pos.terminal', [
        'property' => $s['property']->code, 'outlet' => $s['outlet']->id, 'register' => $s['register']->id,
    ]))->assertOk();

    expect(PosOrder::query()->findOrFail($order->id)->status->value)->toBe('settled')
        ->and($s['register']->fresh()->active_pos_order_id)->toBe($order->id);
});

it('stores, serves and removes the outlet static DuitNow QR and the welcome message', function () {
    $s = posOrderSetup();
    Storage::fake(config('filesystems.public_media'));
    $url = route('settings.pos.outlets.cfd-qr.store', [$s['property']->code, $s['outlet']->id]);

    $this->actingAs($s['manager'])->post($url, ['qr' => UploadedFile::fake()->image('qr.png', 300, 300)])
        ->assertRedirect();

    $path = $s['outlet']->fresh()->cfd_duitnow_qr_path;

    expect($path)->not->toBeNull();
    Storage::disk(config('filesystems.public_media'))->assertExists($path);

    // Replacing the QR generates a fresh cache-busting filename and deletes the previous file.
    $this->actingAs($s['manager'])->post($url, ['qr' => UploadedFile::fake()->image('qr-new.png', 300, 300)])
        ->assertRedirect();

    $replacedPath = $s['outlet']->fresh()->cfd_duitnow_qr_path;
    expect($replacedPath)->not->toBeNull()->not->toBe($path);
    Storage::disk(config('filesystems.public_media'))->assertMissing($path);
    Storage::disk(config('filesystems.public_media'))->assertExists($replacedPath);

    $this->actingAs($s['manager'])->put(route('settings.pos.outlets.update', [$s['property']->code, $s['outlet']->id]), [
        'operation_mode' => 'pay_first', 'rounding_mode' => 'five_sen', 'rounding_policy' => 'cash_leg_only',
        'require_room_charge_signature' => true, 'require_meal_redemption_confirmation' => true, 'require_reprint_pin' => true,
        'require_dual_control' => false, 'print_receipt_after_payment' => true, 'print_drawer_slip' => true, 'require_clock_in' => false,
        'cfd_welcome_message' => 'Welcome to Ocean Breeze',
    ])->assertRedirect();

    expect($s['outlet']->fresh()->cfd_welcome_message)->toBe('Welcome to Ocean Breeze');

    $this->actingAs($s['manager'])->delete(route('settings.pos.outlets.cfd-qr.destroy', [$s['property']->code, $s['outlet']->id]))
        ->assertRedirect();

    expect($s['outlet']->fresh()->cfd_duitnow_qr_path)->toBeNull();
    Storage::disk(config('filesystems.public_media'))->assertMissing($replacedPath);
});
