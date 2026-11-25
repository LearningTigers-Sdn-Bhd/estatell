<?php

use App\Actions\Pos\ResetPosStockCountdowns;
use App\Features\Pos;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\PosOrder;
use App\Models\PosOutletItem;
use App\Services\Pos\PosShiftReview;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosTerminalSetup.php';
require_once __DIR__.'/../Support/PosShiftSetup.php';

it('tells the audit sheet about open shifts and open orders, without blocking', function () {
    $s = posShiftSetup();
    $review = fn () => app(PosShiftReview::class)->for($s['property'], $s['manager']);

    expect($review())->toBeNull();

    $shift = posOpenShift($s);
    expect($review()->message)->toBe('1 POS shift is still open 0 orders are still open.');

    PosOrder::factory()->count(2)->create(['property_id' => $s['property']->id, 'pos_outlet_id' => $s['outlet']->id, 'pos_shift_id' => $shift->id]);
    expect($review()->message)->toBe('1 POS shift is still open 2 orders are still open.')
        ->and($review()->url)->toBeNull();
});

it('goes quiet when Native POS is off or everything is closed', function () {
    $s = posShiftSetup();
    posOpenShift($s);

    Feature::for($s['property'])->deactivate(Pos::class);
    expect(app(PosShiftReview::class)->for($s['property'], $s['manager']))->toBeNull();
});

it('only reports the own property', function () {
    $s = posShiftSetup();
    $other = posShiftSetup();
    posOpenShift($other);

    expect(app(PosShiftReview::class)->for($s['property'], $s['manager']))->toBeNull();
});

it('resets stock counts that have a daily reset number and leaves the rest alone', function () {
    $s = posShiftSetup();
    $property = $s['property'];
    $variant = fn () => PosItemVariant::factory()->create(['property_id' => $property->id]);
    $row = fn (array $attributes) => tap(new PosOutletItem($attributes), function (PosOutletItem $row) use ($property, $s, $variant): void {
        $row->property_id = $property->id;
        $row->pos_outlet_id = $s['outlet']->id;
        $row->pos_item_variant_id = $variant()->id;
        $row->save();
    });

    $reset = $row(['stock_countdown' => 2, 'daily_stock_reset' => 20]);
    $sold = $row(['stock_countdown' => -3, 'daily_stock_reset' => 20]);
    $unset = $row(['stock_countdown' => null, 'daily_stock_reset' => 20]);
    $manual = $row(['stock_countdown' => 5, 'daily_stock_reset' => null]);
    $already = $row(['stock_countdown' => 20, 'daily_stock_reset' => 20]);
    $shared = PosItem::factory()->create(['property_id' => $property->id, 'shared_stock_count' => 1, 'daily_shared_stock_reset' => 12]);
    $sharedManual = PosItem::factory()->create(['property_id' => $property->id, 'shared_stock_count' => 4, 'daily_shared_stock_reset' => null]);
    $otherSetup = posShiftSetup();
    $otherRow = tap(new PosOutletItem(['stock_countdown' => 1, 'daily_stock_reset' => 9]), function (PosOutletItem $row) use ($otherSetup): void {
        $row->property_id = $otherSetup['property']->id;
        $row->pos_outlet_id = $otherSetup['outlet']->id;
        $row->pos_item_variant_id = PosItemVariant::factory()->create(['property_id' => $otherSetup['property']->id])->id;
        $row->save();
    });

    expect(app(ResetPosStockCountdowns::class)($property))->toBe(4)
        ->and($reset->fresh()->stock_countdown)->toBe(20)
        ->and($sold->fresh()->stock_countdown)->toBe(20)
        ->and($unset->fresh()->stock_countdown)->toBe(20)
        ->and($manual->fresh()->stock_countdown)->toBe(5)
        ->and($already->fresh()->stock_countdown)->toBe(20)
        ->and($shared->fresh()->shared_stock_count)->toBe(12)
        ->and($sharedManual->fresh()->shared_stock_count)->toBe(4)
        ->and(app(ResetPosStockCountdowns::class)($property))->toBe(0)
        ->and($otherRow->fresh()->stock_countdown)->toBe(1);
});
