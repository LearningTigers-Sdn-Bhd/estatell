<?php

use App\Actions\Pos\PostPosCharge;
use App\Actions\Pos\SavePosOutlet;
use App\Actions\Pos\SeedPosDiningOptions;
use App\Data\Pos\PosChargeData;
use App\Data\Pos\PosOutletData;
use App\Enums\PosDiningKind;
use App\Enums\PosOperationMode;
use App\Enums\PosRoundingMode;
use App\Enums\PosRoundingPolicy;
use App\Enums\PosTenderType;
use App\Features\Pos;
use App\Models\PaymentMethod;
use App\Models\PosDiningOption;
use App\Models\PosOutlet;
use App\Models\PosOutletPaymentMethod;
use Illuminate\Database\QueryException;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosTerminalSetup.php';
require_once __DIR__.'/../Support/PosSetup.php';

it('saves a native outlet with no charge item and gives it the Malaysian dining options', function () {
    $setup = nativePosSetup();

    $result = app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Lobby Cafe', 'cafe', null, true, true));

    expect($result->isRefused())->toBeFalse();
    $outlet = $result->outlet->fresh();
    expect($outlet->default_charge_item_id)->toBeNull()
        ->and($outlet->pos_enabled)->toBeTrue()
        ->and($outlet->operation_mode)->toBe(PosOperationMode::PayFirst)
        ->and($outlet->rounding_mode)->toBe(PosRoundingMode::FiveSen)
        ->and($outlet->rounding_policy)->toBe(PosRoundingPolicy::CashLegOnly)
        ->and($outlet->require_room_charge_signature)->toBeTrue()
        ->and($outlet->require_dual_control)->toBeFalse();

    $options = $outlet->diningOptions()->orderBy('id')->get();
    expect($options->pluck('name')->all())->toBe(['Dine-In', 'Takeaway', 'Room Service', 'Poolside'])
        ->and($options->pluck('kind')->all())->toBe([PosDiningKind::DineIn, PosDiningKind::Takeaway, PosDiningKind::RoomService, PosDiningKind::Poolside])
        ->and($options->where('is_default', true)->pluck('name')->all())->toBe(['Dine-In'])
        ->and($options->pluck('service_charge_taxable')->unique()->all())->toBe([false])
        ->and($options->firstWhere('name', 'Takeaway')->apply_service_charge)->toBeFalse()
        ->and($options->firstWhere('name', 'Takeaway')->service_charge_rate)->toBe('0.00')
        ->and($options->firstWhere('name', 'Dine-In')->service_charge_rate)->toBe('10.00');
});

it('seeds dining options once and leaves an outlet that already has them alone', function () {
    $setup = nativePosSetup();
    $outlet = $setup['outlet'];

    expect(app(SeedPosDiningOptions::class)($outlet))->toBe(4)
        ->and(app(SeedPosDiningOptions::class)($outlet))->toBe(0)
        ->and(PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->count())->toBe(4);

    app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData($outlet->name, $outlet->code, null, true, true), $outlet);
    expect(PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->count())->toBe(4);
});

it('refuses a native outlet while Native POS is off', function () {
    $setup = nativePosSetup();
    Feature::for($setup['property'])->deactivate(Pos::class);

    $result = app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Cafe', 'CAFE', null, true, true));

    expect($result->refusal)->toBe('pos_disabled')
        ->and(PosOutlet::query()->where('code', 'CAFE')->exists())->toBeFalse();
});

it('keeps the charge item mandatory for an outlet that is not native', function () {
    $setup = nativePosSetup();

    $result = app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Cafe', 'CAFE', null, true, false));

    expect($result->refusal)->toBe('charge_item_invalid');
});

it('lets an external outlet turn native on and keep its charge item', function () {
    $setup = posSetup();
    Feature::for($setup['property'])->activate(Pos::class);

    $result = app(SavePosOutlet::class)($setup['actor'], $setup['property'], new PosOutletData('Main', $setup['outlet']->code, $setup['item']->id, true, true), $setup['outlet']);

    expect($result->isRefused())->toBeFalse()
        ->and($result->outlet->pos_enabled)->toBeTrue()
        ->and($result->outlet->default_charge_item_id)->toBe($setup['item']->id);
});

it('refuses the external POS API on a native-only outlet', function () {
    $setup = nativePosSetup();
    $outlet = app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Cafe', 'CAFE', null, true, true))->outlet;

    $result = app(PostPosCharge::class)($outlet, new PosChargeData('receipt-1', '1001', '12.00', null, []));

    expect($result->refusal)->toBe('outlet_not_external')
        ->and($result->posting)->toBeNull();
});

it('lists a native-only outlet on the outlets page', function () {
    $setup = nativePosSetup();
    app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Cafe', 'CAFE', null, true, true));

    $this->actingAs($setup['manager'])->get(route('settings.pos-outlets.index', $setup['property']->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('outlets', 2)->where('outlets', fn ($outlets) => collect($outlets)->contains(fn (array $row): bool => $row['code'] === 'CAFE' && $row['chargeItemName'] === null && $row['posEnabled'] === true)));
});

it('rejects a native outlet without a charge item at the database level', function () {
    $setup = nativePosSetup();

    expect(fn () => $setup['outlet']->forceFill(['default_charge_item_id' => null, 'pos_enabled' => false])->save())->toThrow(QueryException::class);
});

it('creates a built-in POS outlet without a charge item via HTTP and configures outlet payment methods', function () {
    $setup = nativePosSetup();
    $property = $setup['property'];

    $this->actingAs($setup['manager'])
        ->post(route('settings.pos-outlets.store', $property->code), [
            'name' => 'Pool Bar',
            'code' => 'POOL',
            'pos_enabled' => true,
            'default_charge_item_id' => null,
            'active' => true,
        ])
        ->assertRedirect(route('settings.pos-outlets.index', $property->code));

    $outlet = PosOutlet::query()->where('property_id', $property->id)->where('code', 'POOL')->firstOrFail();
    expect($outlet->pos_enabled)->toBeTrue()
        ->and($outlet->default_charge_item_id)->toBeNull();

    $cash = PaymentMethod::factory()->for($property)->create(['name' => 'Cash', 'active' => true]);

    $this->actingAs($setup['manager'])
        ->post(route('settings.pos.payment-methods.store', [$property->code, $outlet->id]), [
            'payment_method_id' => $cash->id,
            'tender_type' => 'cash',
            'pulse_drawer' => true,
            'active' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    $row = PosOutletPaymentMethod::query()->where('pos_outlet_id', $outlet->id)->sole();
    expect($row->payment_method_id)->toBe($cash->id)
        ->and($row->tender_type)->toBe(PosTenderType::Cash)
        ->and($row->pulse_drawer)->toBeTrue();

    $this->actingAs($setup['manager'])
        ->put(route('settings.pos.payment-methods.update', [$property->code, $outlet->id, $row->id]), [
            'payment_method_id' => $cash->id,
            'tender_type' => 'cash',
            'pulse_drawer' => false,
            'active' => true,
            'sort_order' => 2,
        ])
        ->assertRedirect();

    expect($row->fresh()->pulse_drawer)->toBeFalse()
        ->and($row->fresh()->sort_order)->toBe(2);
});
