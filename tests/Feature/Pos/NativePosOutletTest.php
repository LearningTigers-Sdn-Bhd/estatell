<?php

use App\Actions\Pos\PostPosCharge;
use App\Actions\Pos\SavePosOutlet;
use App\Actions\Pos\SeedPosDiningOptions;
use App\Data\Pos\PosChargeData;
use App\Data\Pos\PosOutletData;
use App\Enums\PosConnectionStatus;
use App\Enums\PosDiningKind;
use App\Enums\PosOperationMode;
use App\Enums\PosProvider;
use App\Enums\PosRoundingMode;
use App\Enums\PosRoundingPolicy;
use App\Features\NativePos;
use App\Models\PosConnection;
use App\Models\PosDiningOption;
use App\Models\PosOutlet;
use App\Models\PosOutletMapping;
use Illuminate\Database\QueryException;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/NativePosSetup.php';
require_once __DIR__.'/../Support/PosSetup.php';

it('saves a native outlet with no charge item and gives it the Malaysian dining options', function () {
    $setup = nativePosSetup();

    $result = app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Lobby Cafe', 'cafe', null, true, true));

    expect($result->isRefused())->toBeFalse();
    $outlet = $result->outlet->fresh();
    expect($outlet->default_charge_item_id)->toBeNull()
        ->and($outlet->native_pos_enabled)->toBeTrue()
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
    Feature::for($setup['property'])->deactivate(NativePos::class);

    $result = app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Cafe', 'CAFE', null, true, true));

    expect($result->refusal)->toBe('native_pos_disabled')
        ->and(PosOutlet::query()->where('code', 'CAFE')->exists())->toBeFalse();
});

it('keeps the charge item mandatory for an outlet that is not native', function () {
    $setup = nativePosSetup();

    $result = app(SavePosOutlet::class)($setup['manager'], $setup['property'], new PosOutletData('Cafe', 'CAFE', null, true, false));

    expect($result->refusal)->toBe('charge_item_invalid');
});

it('keeps the charge item mandatory while an external mapping is live', function () {
    $setup = posSetup();
    $property = $setup['property'];
    Feature::for($property)->activate(NativePos::class);
    $connection = new PosConnection(['provider' => PosProvider::Loyverse, 'status' => PosConnectionStatus::Connected]);
    $connection->property_id = $property->id;
    $connection->save();
    $mapping = new PosOutletMapping(['external_store_id' => 's1', 'external_payment_type_id' => 'p1', 'active' => true]);
    $mapping->property_id = $property->id;
    $mapping->pos_connection_id = $connection->id;
    $mapping->pos_outlet_id = $setup['outlet']->id;
    $mapping->save();

    $result = app(SavePosOutlet::class)($setup['actor'], $property, new PosOutletData('Main', $setup['outlet']->code, null, true, true), $setup['outlet']);

    expect($result->refusal)->toBe('charge_item_invalid')
        ->and($setup['outlet']->fresh()->default_charge_item_id)->not->toBeNull();
});

it('lets an external outlet turn native on and keep its charge item', function () {
    $setup = posSetup();
    Feature::for($setup['property'])->activate(NativePos::class);

    $result = app(SavePosOutlet::class)($setup['actor'], $setup['property'], new PosOutletData('Main', $setup['outlet']->code, $setup['item']->id, true, true), $setup['outlet']);

    expect($result->isRefused())->toBeFalse()
        ->and($result->outlet->native_pos_enabled)->toBeTrue()
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
        ->assertInertia(fn ($page) => $page->has('outlets', 2)->where('outlets', fn ($outlets) => collect($outlets)->contains(fn (array $row): bool => $row['code'] === 'CAFE' && $row['chargeItemName'] === null && $row['nativePosEnabled'] === true)));
});

it('rejects a native outlet without a charge item at the database level', function () {
    $setup = nativePosSetup();

    expect(fn () => $setup['outlet']->forceFill(['default_charge_item_id' => null, 'native_pos_enabled' => false])->save())->toThrow(QueryException::class);
});
