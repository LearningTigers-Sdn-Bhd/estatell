<?php

use App\Actions\Folio\PostManualBatch;
use App\Data\Folio\ManualPreviewData;
use App\Data\Folio\ManualRowData;
use App\Data\Folio\ManualRowPreviewData;
use App\Enums\ManualRowType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Tax;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\ManualFolioPreview;

require_once __DIR__.'/../Support/ManualFolioSetup.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function mpRow(ManualRowType $type, array $setup, array $attributes = []): ManualRowData
{
    return ManualRowData::from([
        'key' => fake()->uuid(),
        'type' => $type,
        'serviceDate' => '2026-10-11',
        'folioId' => $setup['folio']->id,
        'unitId' => $setup['unit']->id,
        'chargeItemId' => $type === ManualRowType::Charge ? $setup['minibar']->id : null,
        'quantity' => 1,
        'amount' => '6.00',
        'paymentMethodId' => $type === ManualRowType::Payment ? $setup['cash']->id : null,
        ...$attributes,
    ]);
}

/**
 * @param  list<ManualRowData>  $rows
 */
function mpPreview(array $setup, array $rows): ManualPreviewData
{
    return app(ManualFolioPreview::class)->preview($setup['user'], $setup['property']->fresh(['settings']), $setup['reservation'], $setup['folio'], $rows);
}

test('a charge row shows its tax lines and total, and the rail moves by it', function () {
    $setup = mfSetup();

    $preview = mpPreview($setup, [mpRow(ManualRowType::Charge, $setup, ['quantity' => 2])]);

    expect($preview->rows[0]->total)->toBe('12.96')
        ->and($preview->rows[0]->refusal)->toBeNull()
        ->and($preview->rows[0]->taxes)->toHaveCount(1)
        ->and($preview->rows[0]->taxes[0]->name)->toBe('SST')
        ->and($preview->rows[0]->taxes[0]->amount)->toBe('0.96')
        ->and([$preview->chargesNow, $preview->chargesAfter, $preview->paymentsAfter, $preview->balanceNow, $preview->balanceAfter])->toBe(['0.00', '12.96', '0.00', '0.00', '12.96'])
        ->and($preview->folios)->toHaveCount(1);
});

test('chosen taxes replace the item taxes in the preview', function () {
    $setup = mfSetup();
    $tourism = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism Tax', 'rate' => '10.00', 'is_inclusive' => false]);

    $none = mpPreview($setup, [mpRow(ManualRowType::Charge, $setup, ['taxIds' => []])])->rows[0];
    $other = mpPreview($setup, [mpRow(ManualRowType::Charge, $setup, ['taxIds' => [$tourism->id]])])->rows[0];

    expect($none->total)->toBe('6.00')->and($none->taxes)->toBeEmpty()
        ->and($other->total)->toBe('6.60')->and($other->taxes[0]->name)->toBe('Tourism Tax');
});

test('a foreign-only tax shows as exempt for a local guest', function () {
    $setup = mfSetup();
    $tourism = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism Tax', 'rate' => '10.00', 'is_inclusive' => false, 'foreign_guests_only' => true]);
    $setup['reservation']->booker->forceFill(['nationality_code' => $setup['property']->country_code])->save();

    $row = mpPreview($setup, [mpRow(ManualRowType::Charge, $setup, ['taxIds' => [$tourism->id]])])->rows[0];

    expect($row->total)->toBe('6.00')
        ->and($row->taxes[0]->exempt)->toBeTrue()
        ->and($row->taxes[0]->amount)->toBe('0.00');
});

test('payment and refund rows move the payments, and a refused row adds nothing', function () {
    $setup = mfSetup();
    $paid = app(PostManualBatch::class)($setup['user'], $setup['reservation'], $setup['folio'], 'Deposit', [mpRow(ManualRowType::Payment, $setup, ['amount' => '100.00'])], false, fake()->uuid());
    $payment = Payment::query()->where('folio_line_id', $paid->batch->lines()->firstOrFail()->id)->firstOrFail();

    $preview = mpPreview($setup, [
        mpRow(ManualRowType::Payment, $setup, ['amount' => '30.00']),
        mpRow(ManualRowType::Refund, $setup, ['amount' => '20.00', 'paymentId' => $payment->id]),
        mpRow(ManualRowType::Refund, $setup, ['amount' => '90.00', 'paymentId' => $payment->id]),
    ]);

    expect($preview->rows[0]->total)->toBe('-30.00')
        ->and($preview->rows[1]->total)->toBe('20.00')
        ->and($preview->rows[2]->total)->toBeNull()
        ->and($preview->rows[2]->field)->toBe('amount')
        ->and([$preview->paymentsNow, $preview->paymentsAfter, $preview->balanceNow, $preview->balanceAfter])->toBe(['100.00', '110.00', '-100.00', '-110.00']);
});

test('refused rows say which field and why', function (array $attributes, string $field) {
    $setup = mfSetup();

    $row = mpPreview($setup, [mpRow(ManualRowType::Charge, $setup, $attributes)])->rows[0];

    expect($row->total)->toBeNull()
        ->and($row->field)->toBe($field)
        ->and($row->refusal)->not->toBeEmpty();
})->with([
    'date after today' => [['serviceDate' => '2026-10-14'], 'service_date'],
    'date before the booking' => [['serviceDate' => '2026-10-09'], 'service_date'],
    'no amount' => [['amount' => ''], 'unit_amount'],
    'no quantity' => [['quantity' => 0], 'quantity'],
    'no item' => [['chargeItemId' => null], 'charge_item_id'],
    'no room on a master folio' => [['unitId' => null], 'unit_id'],
    'a tax of another property' => [['taxIds' => [0]], 'tax_ids'],
]);

test('what the preview totals is what the batch posts', function () {
    $setup = mfSetup();
    $rows = [
        mpRow(ManualRowType::Charge, $setup, ['quantity' => 3, 'amount' => '7.50']),
        mpRow(ManualRowType::Charge, $setup, ['chargeItemId' => $setup['laundry']->id, 'amount' => '25.00']),
        mpRow(ManualRowType::Payment, $setup, ['amount' => '40.00']),
    ];

    $preview = mpPreview($setup, $rows);
    app(PostManualBatch::class)($setup['user'], $setup['reservation'], $setup['folio'], 'Paper folio', $rows, false, fake()->uuid());

    expect($preview->balanceAfter)->toBe(app(FolioBalance::class)->forFolio($setup['folio']))
        ->and(collect($preview->rows)->map(fn (ManualRowPreviewData $row): string => $row->total)->all())->toBe(['24.30', '25.00', '-40.00']);
});

test('the rail names every folio the rows touch and explains why the folio cannot close', function () {
    $setup = mfSetup();
    $third = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $preview = mpPreview($setup, [mpRow(ManualRowType::Charge, $setup, ['folioId' => $third->id])]);

    expect(collect($preview->folios)->pluck('folioId')->all())->toBe([$setup['folio']->id, $third->id])
        ->and($preview->closeBlocked)->toContain('still in-house');

    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDays(3), 'checked_out_at' => now()])->save();

    expect(mpPreview($setup, [])->closeBlocked)->toBeNull();
});
