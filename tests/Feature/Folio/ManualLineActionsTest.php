<?php

use App\Actions\Folio\AddLineTax;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\RecalculateLineTax;
use App\Actions\Folio\RemoveLineTax;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\SplitLine;
use App\Data\Folio\NewChargeData;
use App\Data\Folio\SplitPartData;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioManualBatch;
use App\Services\Folio\FolioBalance;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ManualFolioSetup.php';

/**
 * Posts a charge of the given item on the setup's master folio and returns its line.
 *
 * @param  array<string, mixed>  $setup
 */
function mlCharge(array $setup, ChargeItem $item, string $amount = '100.00', ?Folio $folio = null): FolioLine
{
    $result = app(PostCharge::class)($setup['user'], $setup['unit'], new NewChargeData(
        chargeItemId: $item->id,
        transactionCodeId: null,
        description: null,
        quantity: 1,
        unitAmount: $amount,
        folioId: ($folio ?? $setup['folio'])->id,
    ));

    return $result->line();
}

function mlOther(array $setup): Folio
{
    return Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
}

function mlBalance(Folio $folio): string
{
    return app(FolioBalance::class)->forFolio($folio);
}

test('split reverses the line and posts one charge per part with its own tax', function () {
    $setup = mfSetup();
    $other = mlOther($setup);
    $line = mlCharge($setup, $setup['minibar']);

    $result = app(SplitLine::class)($setup['user'], $line, [
        new SplitPartData($setup['folio']->id, '60.00'),
        new SplitPartData($other->id, '40.00'),
    ], 'A charge was missed', 'key-split-1');

    $charges = FolioLine::query()->where('manual_batch_id', FolioManualBatch::query()->firstOrFail()->id)->where('kind', TransactionKind::Charge)->whereNull('reversal_of_id')->orderBy('id')->get();
    $taxes = FolioLine::query()->where('kind', TransactionKind::Tax)->whereNull('reversal_of_id')->whereIn('parent_line_id', $charges->pluck('id'))->orderBy('id')->pluck('amount')->all();

    expect($result->isRefused())->toBeFalse()
        ->and($charges->pluck('amount')->all())->toBe(['60.00', '40.00'])
        ->and($charges->pluck('folio_id')->all())->toBe([$setup['folio']->id, $other->id])
        ->and($taxes)->toBe(['4.80', '3.20'])
        ->and($charges->pluck('service_date')->map->toDateString()->unique()->all())->toBe([$line->service_date->toDateString()])
        ->and($charges->pluck('transfer_group_id')->unique()->count())->toBe(1)
        ->and(FolioLine::query()->where('reversal_of_id', $line->id)->exists())->toBeTrue()
        ->and(FolioManualBatch::query()->firstOrFail()->reason)->toBe('A charge was missed')
        ->and(mlBalance($setup['folio']))->toBe('64.80')
        ->and(mlBalance($other))->toBe('43.20')
        ->and(Activity::query()->where('event', 'folio.line_split')->count())->toBe(1);
});

test('split refuses parts that do not add up and writes nothing', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $before = FolioLine::query()->count();

    $result = app(SplitLine::class)($setup['user'], $line, [
        new SplitPartData($setup['folio']->id, '60.00'),
        new SplitPartData(mlOther($setup)->id, '30.00'),
    ], 'A charge was missed', 'key-split-2');

    expect($result->refusal)->toBe(PostingResult::REFUSAL_SPLIT_TOTAL_MISMATCH)
        ->and(FolioLine::query()->count())->toBe($before)
        ->and(FolioManualBatch::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'folio.line_split')->count())->toBe(0);
});

test('split refuses one part, a closed folio, a child line and a charge with a discount', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $closed = mlOther($setup);
    $closed->forceFill(['status' => FolioStatus::Closed])->save();
    $taxLine = $line->children()->firstOrFail();
    $split = fn (FolioLine $target, array $parts, string $key) => app(SplitLine::class)($setup['user'], $target, $parts, 'A charge was missed', $key);

    expect($split($line, [new SplitPartData($setup['folio']->id, '108.00')], 'k1')->refusal)->toBe(PostingResult::REFUSAL_SPLIT_INVALID)
        ->and($split($line, [new SplitPartData($setup['folio']->id, '50.00'), new SplitPartData($closed->id, '50.00')], 'k2')->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and($split($taxLine, [new SplitPartData($setup['folio']->id, '4.00'), new SplitPartData($setup['folio']->id, '4.00')], 'k3')->refusal)->toBe(PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE);

    FolioLine::factory()->create([
        'folio_id' => $setup['folio']->id,
        'parent_line_id' => $line->id,
        'transaction_code_id' => $line->transaction_code_id,
        'kind' => TransactionKind::Adjustment,
        'amount' => '-10.00',
        'unit_amount' => '-10.00',
        'quantity' => 1,
        'idempotency_key' => 'discount:test',
    ]);

    expect($split($line, [new SplitPartData($setup['folio']->id, '50.00'), new SplitPartData($setup['folio']->id, '50.00')], 'k4')->refusal)->toBe(PostingResult::REFUSAL_LINE_HAS_DISCOUNT);
});

test('a repeated split form returns the batch and writes nothing', function () {
    $setup = mfSetup();
    $other = mlOther($setup);
    $line = mlCharge($setup, $setup['minibar']);
    $parts = [new SplitPartData($setup['folio']->id, '60.00'), new SplitPartData($other->id, '40.00')];

    app(SplitLine::class)($setup['user'], $line, $parts, 'A charge was missed', 'key-split-3');
    $count = FolioLine::query()->count();
    $again = app(SplitLine::class)($setup['user'], $line, $parts, 'A charge was missed', 'key-split-3');

    expect($again->replayed)->toBeTrue()
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(FolioManualBatch::query()->count())->toBe(1);
});

test('remove tax reverses only the chosen tax line and leaves the charge', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $taxLine = $line->children()->firstOrFail();

    $result = app(RemoveLineTax::class)($setup['user'], $line, [$taxLine->id], 'Guest is exempt', 'key-remove-1');
    $reversal = FolioLine::query()->where('reversal_of_id', $taxLine->id)->firstOrFail();

    expect($result->isRefused())->toBeFalse()
        ->and($reversal->amount)->toBe('-8.00')
        ->and($reversal->parent_line_id)->toBeNull()
        ->and($reversal->manual_batch_id)->toBe(FolioManualBatch::query()->firstOrFail()->id)
        ->and(FolioLine::query()->where('reversal_of_id', $line->id)->exists())->toBeFalse()
        ->and(mlBalance($setup['folio']))->toBe('100.00')
        ->and(Activity::query()->where('event', 'folio.line_tax_removed')->count())->toBe(1);

    $again = app(RemoveLineTax::class)($setup['user'], $line, [$taxLine->id], 'Guest is exempt', 'key-remove-2');
    $stranger = app(RemoveLineTax::class)($setup['user'], $line, [999999], 'Guest is exempt', 'key-remove-3');

    expect($again->refusal)->toBe(PostingResult::REFUSAL_ALREADY_REVERSED)
        ->and($stranger->refusal)->toBe(PostingResult::REFUSAL_TAX_NOT_ON_LINE)
        ->and(FolioManualBatch::query()->count())->toBe(1);
});

test('add tax posts a tax line under the charge and a second add after a removal gets a new key', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['laundry'], '25.00');

    $result = app(AddLineTax::class)($setup['user'], $line, [$setup['sst']->id], 'Tax was missed', 'key-add-1');
    $added = $result->line();

    expect($result->isRefused())->toBeFalse()
        ->and($added->parent_line_id)->toBe($line->id)
        ->and($added->amount)->toBe('2.00')
        ->and($added->idempotency_key)->toBe('tax-add:'.$line->id.':'.$setup['sst']->id.':0')
        ->and(mlBalance($setup['folio']))->toBe('27.00')
        ->and(app(AddLineTax::class)($setup['user'], $line, [$setup['sst']->id], 'Tax was missed', 'key-add-2')->refusal)->toBe(PostingResult::REFUSAL_TAX_ALREADY_ON_LINE);

    app(RemoveLineTax::class)($setup['user'], $line, [$added->id], 'Guest is exempt', 'key-add-3');
    $second = app(AddLineTax::class)($setup['user'], $line, [$setup['sst']->id], 'Tax was missed', 'key-add-4');

    expect($second->line()->idempotency_key)->toBe('tax-add:'.$line->id.':'.$setup['sst']->id.':1')
        ->and(mlBalance($setup['folio']))->toBe('27.00');
});

test('recalculate tax puts the item taxes back and refuses when nothing changes', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);

    expect(app(RecalculateLineTax::class)($setup['user'], $line, 'Fixing a mistake', 'key-recalc-1')->refusal)->toBe(PostingResult::REFUSAL_TAX_UNCHANGED);

    app(RemoveLineTax::class)($setup['user'], $line, [$line->children()->firstOrFail()->id], 'Guest is exempt', 'key-recalc-2');
    $result = app(RecalculateLineTax::class)($setup['user'], $line, 'Fixing a mistake', 'key-recalc-3');

    expect($result->isRefused())->toBeFalse()
        ->and($result->line()->idempotency_key)->toBe('tax-recalc:'.$line->id.':0:tax:'.$setup['sst']->id)
        ->and($result->line()->amount)->toBe('8.00')
        ->and(mlBalance($setup['folio']))->toBe('108.00')
        ->and(Activity::query()->where('event', 'folio.line_tax_recalculated')->count())->toBe(1);
});

test('the tax actions refuse a closed folio and a reversed charge', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $taxLineId = $line->children()->firstOrFail()->id;
    $setup['folio']->forceFill(['status' => FolioStatus::Closed])->save();

    expect(app(RemoveLineTax::class)($setup['user'], $line, [$taxLineId], 'Guest is exempt', 'k1')->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(app(AddLineTax::class)($setup['user'], $line, [$setup['sst']->id], 'Tax was missed', 'k2')->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(app(RecalculateLineTax::class)($setup['user'], $line, 'Fixing a mistake', 'k3')->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN);

    $setup['folio']->forceFill(['status' => FolioStatus::Open])->save();
    app(ReverseLine::class)($setup['user'], $line);

    expect(app(AddLineTax::class)($setup['user'], $line, [$setup['sst']->id], 'Tax was missed', 'k4')->refusal)->toBe(PostingResult::REFUSAL_ALREADY_REVERSED);
});
