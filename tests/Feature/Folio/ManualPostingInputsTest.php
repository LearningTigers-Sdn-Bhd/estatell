<?php

use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RefundPayment;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentPurpose;
use App\Enums\PermissionEnum;
use App\Models\FolioLine;
use App\Models\FolioManualBatch;
use App\Models\Tax;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ManualFolioSetup.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function mfCharge(array $setup, array $attributes = []): NewChargeData
{
    return NewChargeData::from([
        'chargeItemId' => $setup['minibar']->id,
        'transactionCodeId' => null,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '6.00',
        'idempotencyKey' => 'manual:'.fake()->uuid(),
        'folioId' => $setup['folio']->id,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mfPayment(array $setup, array $attributes = []): NewPaymentData
{
    return NewPaymentData::from([
        'paymentMethodId' => $setup['cash']->id,
        'purpose' => PaymentPurpose::Settlement,
        'amount' => '100.00',
        'reference' => null,
        'idempotencyKey' => fake()->uuid(),
        ...$attributes,
    ]);
}

test('a charge keeps its service date and posts on the business date, with the batch on the charge only', function () {
    $setup = mfSetup();
    $batch = FolioManualBatch::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $setup['folio']->id, 'user_id' => $setup['user']->id]);

    $result = app(PostCharge::class)($setup['user'], $setup['unit'], mfCharge($setup, ['serviceDate' => '2026-10-11', 'manualBatchId' => $batch->id]));

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toHaveCount(2)
        ->and(collect($result->lines)->map(fn (FolioLine $line): array => [$line->posting_date->toDateString(), $line->service_date->toDateString()])->unique()->values()->all())->toBe([['2026-10-13', '2026-10-11']])
        ->and($result->lines[0]->manual_batch_id)->toBe($batch->id)
        ->and($result->lines[1]->manual_batch_id)->toBeNull()
        ->and(Activity::query()->where('event', 'folio.charge_posted')->latest('id')->first()->properties['manual_batch_id'])->toBe($batch->id);
});

test('a charge without a service date or batch behaves as before', function () {
    $setup = mfSetup();

    $lines = app(PostCharge::class)($setup['user'], $setup['unit'], mfCharge($setup))->lines;

    expect($lines[0]->service_date->toDateString())->toBe('2026-10-13')
        ->and($lines[0]->manual_batch_id)->toBeNull();
});

test('a service date outside the range refuses and writes nothing', function (string $date) {
    $setup = mfSetup();

    $result = app(PostCharge::class)($setup['user'], $setup['unit'], mfCharge($setup, ['serviceDate' => $date]));

    expect($result->refusal)->toBe(PostingResult::REFUSAL_SERVICE_DATE_INVALID)
        ->and(FolioLine::query()->count())->toBe(0);
})->with([
    'before the booking' => '2026-10-09',
    'after the business date' => '2026-10-14',
    'not a date' => '11 Oct',
    'not a real day' => '2026-02-30',
]);

test('chosen taxes replace the item taxes, and an empty list posts no tax', function () {
    $setup = mfSetup();
    $service = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism Tax', 'rate' => '10.00', 'is_inclusive' => false]);

    $chosen = app(PostCharge::class)($setup['user'], $setup['unit'], mfCharge($setup, ['taxIds' => [$service->id]]))->lines;
    $none = app(PostCharge::class)($setup['user'], $setup['unit'], mfCharge($setup, ['taxIds' => []]))->lines;
    $default = app(PostCharge::class)($setup['user'], $setup['unit'], mfCharge($setup))->lines;

    expect(collect($chosen)->pluck('description')->all())->toBe(['Minibar', 'Tourism Tax'])
        ->and($none)->toHaveCount(1)
        ->and(collect($default)->pluck('description')->all())->toBe(['Minibar', 'SST']);
});

test('a tax of another property refuses', function () {
    $setup = mfSetup();
    $other = Tax::factory()->create();

    $result = app(PostCharge::class)($setup['user'], $setup['unit'], mfCharge($setup, ['taxIds' => [$other->id]]));

    expect($result->refusal)->toBe(PostingResult::REFUSAL_TAX_NOT_AVAILABLE)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('a payment and a refund keep their service date and batch', function () {
    $setup = mfSetup();
    $batch = FolioManualBatch::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $setup['folio']->id, 'user_id' => $setup['user']->id]);

    $payment = app(RecordPayment::class)($setup['user'], $setup['reservation'], $setup['folio'], null, mfPayment($setup, ['serviceDate' => '2026-10-11', 'manualBatchId' => $batch->id]));
    $refund = app(RefundPayment::class)($setup['user'], $payment->payment, '30.00', null, fake()->uuid(), '2026-10-12', $batch->id);

    expect($payment->isRefused())->toBeFalse()
        ->and($payment->payment->folioLine->service_date->toDateString())->toBe('2026-10-11')
        ->and($payment->payment->folioLine->posting_date->toDateString())->toBe('2026-10-13')
        ->and($payment->payment->folioLine->manual_batch_id)->toBe($batch->id)
        ->and($refund->payment->folioLine->service_date->toDateString())->toBe('2026-10-12')
        ->and($refund->payment->folioLine->manual_batch_id)->toBe($batch->id);
});

test('a payment or refund with a service date outside the range refuses', function () {
    $setup = mfSetup();
    $payment = app(RecordPayment::class)($setup['user'], $setup['reservation'], $setup['folio'], null, mfPayment($setup))->payment;

    $late = app(RecordPayment::class)($setup['user'], $setup['reservation'], $setup['folio'], null, mfPayment($setup, ['serviceDate' => '2026-10-14']));
    $early = app(RefundPayment::class)($setup['user'], $payment, '10.00', null, fake()->uuid(), '2026-10-09');

    expect($late->refusal)->toBe(PaymentResult::REFUSAL_SERVICE_DATE_INVALID)
        ->and($early->refusal)->toBe(PaymentResult::REFUSAL_SERVICE_DATE_INVALID)
        ->and(FolioLine::query()->count())->toBe(1);
});

test('the manual permissions go to the default roles', function () {
    expect(DefaultRoleEnum::Manager->permissions())->toContain(PermissionEnum::FolioManual, PermissionEnum::FolioViewManualEntries)
        ->and(DefaultRoleEnum::Finance->permissions())->toContain(PermissionEnum::FolioViewManualEntries)->not->toContain(PermissionEnum::FolioManual)
        ->and(DefaultRoleEnum::FrontDesk->permissions())->not->toContain(PermissionEnum::FolioManual, PermissionEnum::FolioViewManualEntries);
});
