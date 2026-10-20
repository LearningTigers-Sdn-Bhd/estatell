<?php

use App\Actions\Folio\EditLine;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Data\Folio\EditLineData;
use App\Data\Folio\NewChargeData;
use App\Enums\DefaultRoleEnum;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioManualBatch;
use App\Models\Tax;
use App\Models\UnitNight;
use App\Services\Folio\FolioBalance;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ManualFolioSetup.php';

/**
 * Posts a Minibar charge on the setup's master folio (6.00 with 8% SST unless told otherwise) and returns its line.
 *
 * @param  array<string, mixed>  $setup
 */
function elCharge(array $setup, string $amount = '100.00', ?string $key = null): FolioLine
{
    return app(PostCharge::class)($setup['user'], $setup['unit'], new NewChargeData(
        chargeItemId: $setup['minibar']->id,
        transactionCodeId: null,
        description: 'Minibar',
        quantity: 1,
        unitAmount: $amount,
        idempotencyKey: $key,
        folioId: $setup['folio']->id,
    ))->line();
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $values
 */
function elEdit(array $setup, FolioLine $line, array $values = [], string $key = 'key-edit-1'): PostingResult
{
    $values = [
        'description' => 'Minibar',
        'amount' => '100.00',
        'quantity' => 1,
        'taxIds' => [$setup['sst']->id],
        ...$values,
    ];

    return app(EditLine::class)($setup['user'], $line, new EditLineData($values['description'], $values['amount'], $values['quantity'], $values['taxIds']), 'Rate was wrong in the old system', $key);
}

test('edit line reverses the charge and posts it again with the new values, tax and the original service date', function () {
    $setup = mfSetup();
    $line = elCharge($setup);
    $other = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism', 'rate' => '10.00', 'is_inclusive' => false]);
    $other->applicableTransactionCodes()->attach($setup['minibar']->transaction_code_id);

    $result = elEdit($setup, $line, ['description' => 'Minibar, 2 drinks', 'amount' => '60.00', 'quantity' => 2, 'taxIds' => [$other->id]]);

    $new = FolioLine::query()->where('idempotency_key', 'edit:'.$line->id)->firstOrFail();
    $tax = FolioLine::query()->where('parent_line_id', $new->id)->where('kind', TransactionKind::Tax)->firstOrFail();

    expect($result->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('reversal_of_id', $line->id)->exists())->toBeTrue()
        ->and($new->description)->toBe('Minibar, 2 drinks')
        ->and($new->unit_amount)->toBe('60.00')
        ->and($new->quantity)->toBe(2)
        ->and($new->amount)->toBe('120.00')
        ->and($tax->amount)->toBe('12.00')
        ->and($new->service_date->toDateString())->toBe($line->service_date->toDateString())
        ->and($new->posting_date->toDateString())->toBe('2026-10-13')
        ->and($new->manual_batch_id)->toBe(FolioManualBatch::query()->sole()->id)
        ->and(FolioManualBatch::query()->sole()->reason)->toBe('Rate was wrong in the old system')
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe('132.00')
        ->and(Activity::query()->where('event', 'folio.line_edited')->sole()->properties['old']['unit_amount'])->toBe('100.00');
});

test('a taxless edit leaves no tax line, and the same form key edits once', function () {
    $setup = mfSetup();
    $line = elCharge($setup);

    $first = elEdit($setup, $line, ['amount' => '50.00', 'taxIds' => []], 'key-edit-same');
    $again = elEdit($setup, $line, ['amount' => '50.00', 'taxIds' => []], 'key-edit-same');

    expect($first->isRefused())->toBeFalse()
        ->and($again->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('idempotency_key', 'edit:'.$line->id)->count())->toBe(1)
        ->and(FolioManualBatch::query()->count())->toBe(1)
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe('50.00');
});

test('edit line refuses what it cannot post and writes nothing', function (array $values, string $refusal) {
    $setup = mfSetup();
    $line = elCharge($setup);

    $result = elEdit($setup, $line, $values);

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->whereNotNull('reversal_of_id')->exists())->toBeFalse()
        ->and(FolioManualBatch::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'folio.line_edited')->exists())->toBeFalse();
})->with([
    'nothing different' => [[], PostingResult::REFUSAL_NOTHING_CHANGED],
    'no description' => [['description' => '  '], PostingResult::REFUSAL_EDIT_INVALID],
    'no amount' => [['amount' => '0.00'], PostingResult::REFUSAL_AMOUNT_INVALID],
    'no quantity' => [['quantity' => 0], PostingResult::REFUSAL_QUANTITY_INVALID],
    'a tax of another property' => [['taxIds' => [0]], PostingResult::REFUSAL_TAX_NOT_AVAILABLE],
]);

test('edit line refuses a room night line, a closed folio and a line that is already reversed', function () {
    $setup = mfSetup();
    $line = elCharge($setup);
    $night = elCharge($setup, key: 'night:999:fix:1');

    expect(elEdit($setup, $night, ['amount' => '90.00'])->refusal)->toBe(PostingResult::REFUSAL_NIGHT_LINE);

    elEdit($setup, $line, ['amount' => '90.00'], 'key-edit-first');

    expect(elEdit($setup, $line, ['amount' => '80.00'], 'key-edit-second')->refusal)->toBe(PostingResult::REFUSAL_ALREADY_REVERSED);

    $line2 = elCharge($setup);
    Folio::query()->whereKey($setup['folio']->id)->update(['status' => 'closed']);

    expect(elEdit($setup, $line2->fresh(), ['amount' => '70.00'], 'key-edit-third')->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
});

test('the edit routes preview and post, need folio.manual and stay on the route folio', function () {
    $setup = mfSetup();
    $line = elCharge($setup);
    $url = fn (string $name): string => route($name, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id, 'line' => $line->id]);
    $values = ['description' => 'Minibar', 'amount' => '60.00', 'quantity' => 1, 'tax_ids' => [$setup['sst']->id]];

    $this->actingAs($setup['user'])->postJson($url('reservations.folios.lines.edit.preview'), $values)
        ->assertOk()
        ->assertJsonPath('beforeTotal', '108.00')
        ->assertJsonPath('afterTotal', '64.80')
        ->assertJsonPath('difference', '-43.20')
        ->assertJsonPath('refusal', null);
    $this->postJson($url('reservations.folios.lines.edit.preview'), [...$values, 'amount' => '100.00'])
        ->assertJsonPath('field', 'form')
        ->assertJsonPath('afterTotal', null);

    $this->post($url('reservations.folios.lines.edit'), [...$values, 'reason' => 'Fixing a mistake', 'idempotency_key' => fake()->uuid()])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Line edited.']);

    expect(FolioLine::query()->where('idempotency_key', 'edit:'.$line->id)->exists())->toBeTrue();

    $other = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $this->postJson(route('reservations.folios.lines.edit.preview', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $other->id, 'line' => $line->id]), $values)->assertNotFound();
});

test('edit line is refused to staff without folio.manual', function () {
    $setup = mfSetup(DefaultRoleEnum::FrontDesk);
    $line = elCharge($setup);

    $this->actingAs($setup['user'])->post(route('reservations.folios.lines.edit', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id, 'line' => $line->id]), ['description' => 'x'])->assertForbidden();
});

test('a live charge offers Edit line with its values, and a room night line carries its night', function () {
    $setup = mfSetup();
    $line = elCharge($setup);

    $this->actingAs($setup['user'])->get(route('reservations.folios.manual', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manual.lines.'.$line->id.'.description', 'Minibar')
            ->where('manual.lines.'.$line->id.'.unitAmount', '100.00')
            ->where('manual.lines.'.$line->id.'.quantity', 1)
            ->where('manual.lines.'.$line->id.'.taxIds', [$setup['sst']->id])
            ->where('manual.lines.'.$line->id.'.night', null)
            ->where('manual.posted.rows', fn ($rows): bool => collect($rows)->firstWhere('lineId', $line->id)['actions'][0] === 'edit'));
});

test('a room night line carries its night and its price, and an inclusion line of a night has no Edit line', function () {
    $setup = mfSetup();
    $night = UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'date' => '2026-10-13', 'base_amount' => '250.00', 'amount' => '250.00']);
    $room = elCharge($setup, '250.00', 'night:'.$night->id);
    $inclusion = elCharge($setup, '20.00', 'night:'.$night->id.':incl:7');

    $this->actingAs($setup['user'])->get(route('reservations.folios.manual', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manual.lines.'.$room->id.'.night', ['unitId' => $setup['unit']->id, 'nightId' => $night->id, 'price' => '250.00', 'canChangePrice' => true, 'hasDiscount' => false])
            ->where('manual.posted.rows', fn ($rows): bool => in_array('edit', collect($rows)->firstWhere('lineId', $room->id)['actions'], true)
                && ! in_array('edit', collect($rows)->firstWhere('lineId', $inclusion->id)['actions'], true)));
});

test('a room night with a discount offers only Edit line and Reverse, and other discounted charges offer Reverse only', function () {
    $setup = mfSetup();
    $night = UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'date' => '2026-10-13', 'base_amount' => '250.00', 'amount' => '250.00']);
    $room = elCharge($setup, '250.00', 'night:'.$night->id);
    $plain = elCharge($setup);

    foreach ([$room, $plain] as $charge) {
        FolioLine::factory()->create([
            'folio_id' => $setup['folio']->id,
            'parent_line_id' => $charge->id,
            'transaction_code_id' => $charge->transaction_code_id,
            'kind' => TransactionKind::Adjustment,
            'amount' => '-10.00',
            'unit_amount' => '-10.00',
            'quantity' => 1,
            'idempotency_key' => 'discount:'.$charge->id,
        ]);
    }

    $this->actingAs($setup['user'])->get(route('reservations.folios.manual', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manual.lines.'.$room->id.'.night.hasDiscount', true)
            ->missing('manual.lines.'.$plain->id)
            ->where('manual.posted.rows', fn ($rows): bool => collect($rows)->firstWhere('lineId', $room->id)['actions'] === ['edit', 'reverse']
                && collect($rows)->firstWhere('lineId', $plain->id)['actions'] === ['reverse']));
});
