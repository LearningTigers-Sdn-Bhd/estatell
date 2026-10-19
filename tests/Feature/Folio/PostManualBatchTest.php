<?php

use App\Actions\Folio\ManualBatchResult;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\PostManualBatch;
use App\Actions\Payments\PaymentResult;
use App\Data\Folio\ManualRowData;
use App\Enums\FolioStatus;
use App\Enums\ManualRowType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioManualBatch;
use App\Models\Payment;
use App\Services\Folio\FolioBalance;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ManualFolioSetup.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function mbRow(ManualRowType $type, array $setup, array $attributes = []): ManualRowData
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
 * @param  array<string, mixed>  $setup
 * @param  list<ManualRowData>  $rows
 */
function mbPost(array $setup, array $rows, bool $close = false, ?string $key = null): ManualBatchResult
{
    return app(PostManualBatch::class)($setup['user'], $setup['reservation'], $setup['folio'], 'Entering charges from the paper folio', $rows, $close, $key ?? fake()->uuid());
}

function mbReceipts(): int
{
    return (int) DB::table('document_sequences')->where('type', 'receipt')->sum('last_number');
}

test('a batch posts a charge, a payment and a refund with one reason and their service dates', function () {
    $setup = mfSetup();
    $earlier = mbPost($setup, [mbRow(ManualRowType::Payment, $setup, ['amount' => '100.00'])]);
    $paymentId = $earlier->batch->lines()->firstOrFail()->id;
    $payment = Payment::query()->where('folio_line_id', $paymentId)->firstOrFail();

    $result = mbPost($setup, [
        mbRow(ManualRowType::Charge, $setup, ['serviceDate' => '2026-10-11', 'quantity' => 2]),
        mbRow(ManualRowType::Payment, $setup, ['serviceDate' => '2026-10-12', 'amount' => '50.00']),
        mbRow(ManualRowType::Refund, $setup, ['serviceDate' => '2026-10-13', 'amount' => '20.00', 'paymentId' => $payment->id]),
    ]);

    $lines = FolioLine::query()->where('manual_batch_id', $result->batch->id)->orderBy('id')->get();

    expect($result->isRefused())->toBeFalse()
        ->and($result->batch->reason)->toBe('Entering charges from the paper folio')
        ->and($lines->pluck('service_date')->map->toDateString()->all())->toBe(['2026-10-11', '2026-10-12', '2026-10-13'])
        ->and($lines->pluck('posting_date')->map->toDateString()->unique()->all())->toBe(['2026-10-13'])
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe('-117.04')
        ->and(Activity::query()->where('event', 'folio.manual_batch_posted')->count())->toBe(2);
});

test('a refused row rolls back the whole batch, its receipt numbers included, and names the row', function () {
    $setup = mfSetup();
    $before = mbReceipts();
    $good = mbRow(ManualRowType::Payment, $setup, ['amount' => '40.00']);
    $bad = mbRow(ManualRowType::Charge, $setup, ['serviceDate' => '2026-10-20']);

    $result = mbPost($setup, [mbRow(ManualRowType::Charge, $setup), $good, $bad]);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_SERVICE_DATE_INVALID)
        ->and($result->rowKey)->toBe($bad->key)
        ->and($result->rowType)->toBe(ManualRowType::Charge)
        ->and(FolioManualBatch::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(mbReceipts())->toBe($before)
        ->and(Activity::query()->where('event', 'folio.manual_batch_posted')->count())->toBe(0);
});

test('a payment row refusal names a payment row', function () {
    $setup = mfSetup();
    $row = mbRow(ManualRowType::Payment, $setup, ['paymentMethodId' => 999999]);

    $result = mbPost($setup, [$row]);

    expect($result->refusal)->toBe(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE)
        ->and($result->rowKey)->toBe($row->key)
        ->and($result->rowType)->toBe(ManualRowType::Payment);
});

test('the same form key posts once', function () {
    $setup = mfSetup();
    $rows = [mbRow(ManualRowType::Charge, $setup), mbRow(ManualRowType::Payment, $setup, ['amount' => '10.00'])];

    $first = mbPost($setup, $rows, key: 'form-1');
    $second = mbPost($setup, $rows, key: 'form-1');

    expect($second->replayed)->toBeTrue()
        ->and($second->batch->id)->toBe($first->batch->id)
        ->and(FolioLine::query()->count())->toBe(FolioLine::query()->where('manual_batch_id', $first->batch->id)->count() + 1)
        ->and(FolioManualBatch::query()->count())->toBe(1);
});

test('close after posting closes a folio that ends at 0.00', function () {
    $setup = mfSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDays(3), 'checked_out_at' => now()])->save();

    $result = mbPost($setup, [mbRow(ManualRowType::Charge, $setup, ['amount' => '10.00', 'taxIds' => []]), mbRow(ManualRowType::Payment, $setup, ['amount' => '10.00'])], close: true);

    expect($result->isRefused())->toBeFalse()
        ->and($result->closed)->toBeTrue()
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Closed);
});

test('a close that is refused rolls the lines back too', function () {
    $setup = mfSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDays(3), 'checked_out_at' => now()])->save();

    $result = mbPost($setup, [mbRow(ManualRowType::Charge, $setup, ['amount' => '10.00'])], close: true);

    expect($result->refusal)->toBe('balance_not_zero')
        ->and($result->rowKey)->toBeNull()
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(FolioManualBatch::query()->count())->toBe(0)
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Open);
});

test('a charge on a master folio needs a room, and a unit folio brings its own', function () {
    $setup = mfSetup();
    $unitFolio = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $none = mbPost($setup, [mbRow(ManualRowType::Charge, $setup, ['unitId' => null])]);
    $own = mbPost($setup, [mbRow(ManualRowType::Charge, $setup, ['unitId' => null, 'folioId' => $unitFolio->id])]);

    expect($none->refusal)->toBe(ManualBatchResult::REFUSAL_UNIT_REQUIRED)
        ->and($own->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('folio_id', $unitFolio->id)->where('manual_batch_id', $own->batch->id)->exists())->toBeTrue();
});

test('an empty batch and a batch over the limit are refused', function () {
    $setup = mfSetup();

    expect(mbPost($setup, [])->refusal)->toBe(ManualBatchResult::REFUSAL_NOTHING_TO_POST)
        ->and(mbPost($setup, array_map(fn () => mbRow(ManualRowType::Charge, $setup), range(1, 101)))->refusal)->toBe(ManualBatchResult::REFUSAL_TOO_MANY_ROWS);
});

test('a folio of another reservation is a programming error', function () {
    $setup = mfSetup();
    $other = Folio::factory()->master()->create(['property_id' => $setup['property']->id]);

    expect(fn () => mbPost($setup, [mbRow(ManualRowType::Charge, $setup, ['folioId' => $other->id])]))->toThrow(InvalidArgumentException::class);
});
