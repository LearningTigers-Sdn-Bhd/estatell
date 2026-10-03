<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReopenFolio;
use App\Data\Folio\NewChargeData;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, an actor and an open Unit folio that holds one 100.00 charge.
 *
 * @return array{property: Property, actor: User, folio: Folio}
 */
function fcSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create([
        'reservation_id' => Reservation::factory()->create(['property_id' => $property->id])->id,
    ]);
    $actor = User::factory()->create(['property_id' => $property->id]);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);

    $charge = app(PostCharge::class)($actor, $unit, NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $room->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '100.00',
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]))->line();

    return ['property' => $property, 'actor' => $actor, 'folio' => $charge->folio];
}

/** Settles the folio with a payment line, as the payments item will. */
function fcSettle(Folio $folio): void
{
    FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'amount' => '-100.00',
        'unit_amount' => '-100.00',
        'posting_date' => today()->toDateString(),
    ]);
}

test('a settled folio closes, takes an invoice number and logs it', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);

    $result = app(CloseFolio::class)($setup['actor'], $setup['folio']);
    $folio = $setup['folio']->refresh();

    expect($result->isRefused())->toBeFalse()
        ->and($folio->status)->toBe(FolioStatus::Closed)
        ->and($folio->closed_by)->toBe($setup['actor']->id)
        ->and($folio->closed_at)->not->toBeNull()
        ->and($folio->invoice_number)->not->toBeNull()
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('closing refuses a folio with a balance and writes nothing', function () {
    $setup = fcSetup();

    $result = app(CloseFolio::class)($setup['actor'], $setup['folio']);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_BALANCE_NOT_ZERO)
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Open)
        ->and($setup['folio']->invoice_number)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['folio.closed', 'folio.reopened'])->count())->toBe(0);
});

test('closing refuses a folio that is not open', function (string $state) {
    $setup = fcSetup();
    $folio = Folio::factory()->{$state}()->create(['property_id' => $setup['property']->id]);

    $result = app(CloseFolio::class)($setup['actor'], $folio);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_NOT_OPEN)
        ->and(Activity::query()->whereIn('event', ['folio.closed', 'folio.reopened'])->count())->toBe(0);
})->with(['closed', 'voided']);

test('closing the same folio twice does not close it again', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);

    app(CloseFolio::class)($setup['actor'], $setup['folio']);
    $again = app(CloseFolio::class)($setup['actor'], $setup['folio']);

    expect($again->refusal)->toBe(FolioStatusResult::REFUSAL_NOT_OPEN)
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('reopening a closed folio clears the close fields and keeps the invoice number and the ledger', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);
    app(CloseFolio::class)($setup['actor'], $setup['folio']);
    $invoice = $setup['folio']->refresh()->invoice_number;

    $result = app(ReopenFolio::class)($setup['actor'], $setup['folio']);
    $folio = $setup['folio']->refresh();

    expect($result->isRefused())->toBeFalse()
        ->and($folio->status)->toBe(FolioStatus::Open)
        ->and($folio->closed_at)->toBeNull()
        ->and($folio->closed_by)->toBeNull()
        ->and($folio->invoice_number)->toBe($invoice)
        ->and($folio->lines()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'folio.reopened')->count())->toBe(1);

    app(CloseFolio::class)($setup['actor'], $folio);

    expect($folio->refresh()->invoice_number)->toBe($invoice);
});

test('reopening refuses an open or voided folio', function (string $state) {
    $setup = fcSetup();
    $folio = $state === 'open' ? $setup['folio'] : Folio::factory()->voided()->create(['property_id' => $setup['property']->id]);

    $result = app(ReopenFolio::class)($setup['actor'], $folio);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_NOT_CLOSED)
        ->and(Activity::query()->where('event', 'folio.reopened')->count())->toBe(0);
})->with(['open', 'voided']);

test('a closed folio refuses new postings until it is reopened', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);
    app(CloseFolio::class)($setup['actor'], $setup['folio']);

    $post = fn () => app(PostCharge::class)($setup['actor'], $setup['folio']->reservation->units()->first(), NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => TransactionCode::query()->where('property_id', $setup['property']->id)->value('id'),
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '10.00',
        'idempotencyKey' => 'manual:after-close',
    ]));

    expect($post()->refusal)->toBe('folio_not_open');

    app(ReopenFolio::class)($setup['actor'], $setup['folio']);

    expect($post()->isRefused())->toBeFalse();
});
