<?php

use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Events\CancelEvent;
use App\Actions\Events\ConfirmEvent;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\PostEventCharge;
use App\Actions\Events\UpdateEvent;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\ReopenFolio;
use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Enums\EventStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Company;
use App\Models\Event;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TransactionCode;
use App\Services\Folio\FolioBalance;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/EventSetup.php';

function eventCharge(int $chargeItemId, string $amount = '1000.00', ?string $key = null): NewChargeData
{
    return new NewChargeData(
        chargeItemId: $chargeItemId,
        transactionCodeId: null,
        description: null,
        quantity: 1,
        unitAmount: $amount,
        idempotencyKey: $key ?? 'manual:'.fake()->uuid(),
    );
}

/** @param array<string, mixed> $attributes */
function eventPayment(PaymentMethod $method, array $attributes = []): NewPaymentData
{
    return NewPaymentData::from([
        'paymentMethodId' => $method->id,
        'purpose' => PaymentPurpose::Advance,
        'amount' => '500.00',
        'reference' => null,
        'idempotencyKey' => fake()->uuid(),
        ...$attributes,
    ]);
}

test('an event is created with an Event number and one Open Event folio paid by its payer', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();

    [$event, $folio] = makeEvent($property, $owner);

    expect($event->number)->toMatch('/^[A-Z]+-\d{2}9\d+$/')
        ->and($event->status)->toBe(EventStatus::Confirmed)
        ->and($folio->type)->toBe(FolioType::Event)
        ->and($folio->status)->toBe(FolioStatus::Open)
        ->and($folio->reservation_id)->toBeNull()
        ->and($folio->payer_guest_id)->toBe($event->payer_guest_id)
        ->and(Activity::query()->where('event', 'events.created')->where('subject_id', $event->id)->exists())->toBeTrue();
});

test('a walk-in event adds the new guest as its payer', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();

    $event = app(CreateEvent::class)->withBookings($property, $owner, [
        'name' => 'Walk-in lunch',
        'payer_guest_id' => null,
        'payer_company_id' => null,
        'guest' => ['guest_id' => null, 'profile' => ['full_name' => 'Aminah Yusof'], 'update_profile' => false, 'identity' => null],
        'starts_on' => '2026-10-20',
        'ends_on' => '2026-10-20',
        'status' => EventStatus::Confirmed,
    ], [])->event;
    $folio = Folio::query()->where('event_id', $event->id)->sole();

    $guest = Guest::query()->findOrFail($event->payer_guest_id);
    expect($guest->full_name)->toBe('Aminah Yusof')
        ->and($guest->property_id)->toBe($property->id)
        ->and($folio->payer_guest_id)->toBe($guest->id);
});

test('the database allows one active folio per event and never a folio with both owners', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [, $folio] = makeEvent($property, $owner);

    expect(fn () => Folio::query()->insert([...$folio->only(['property_id', 'event_id', 'type', 'status']), 'number' => 'X-1', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
    expect(fn () => $folio->forceFill(['reservation_id' => Folio::factory()->create(['property_id' => $property->id])->reservation_id])->save())
        ->toThrow(QueryException::class);
});

test('changing the payer of an event moves the Open folio to the new payer', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner);
    $company = Company::factory()->create(['property_id' => $property->id]);

    $saved = app(UpdateEvent::class)($owner, $event, [
        'name' => 'Sabah Tourism meeting',
        'payer_guest_id' => null,
        'payer_company_id' => $company->id,
        'starts_on' => '2026-10-20',
        'ends_on' => '2026-10-21',
        'notes' => 'Projector in the hall',
    ]);

    expect($saved->isRefused())->toBeFalse()
        ->and($event->refresh()->ends_on->toDateString())->toBe('2026-10-21')
        ->and($folio->refresh()->payer_company_id)->toBe($company->id)
        ->and($folio->payer_guest_id)->toBeNull();
});

test('confirm and cancel follow the event status rules and a refusal logs nothing', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$tentative] = makeEvent($property, $owner, ['status' => EventStatus::Tentative]);
    [$confirmed] = makeEvent($property, $owner);

    expect(app(ConfirmEvent::class)($owner, $tentative)->isRefused())->toBeFalse()
        ->and($tentative->refresh()->status)->toBe(EventStatus::Confirmed)
        ->and(app(ConfirmEvent::class)($owner, $confirmed)->refusal)->toBe('event_not_tentative')
        ->and(Activity::query()->where('event', 'events.confirmed')->where('subject_id', $confirmed->id)->exists())->toBeFalse();

    expect(app(CancelEvent::class)($owner, $confirmed))->toBeTrue()
        ->and($confirmed->refresh()->status)->toBe(EventStatus::Cancelled)
        ->and($confirmed->cancelled_by)->toBe($owner->id)
        ->and(app(CancelEvent::class)($owner, $confirmed))->toBeFalse()
        ->and(Activity::query()->where('event', 'events.cancelled')->where('subject_id', $confirmed->id)->count())->toBe(1)
        ->and(app(UpdateEvent::class)($owner, $confirmed, ['name' => 'New', 'payer_guest_id' => $confirmed->payer_guest_id, 'payer_company_id' => null, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20'])->refusal)->toBe('event_not_open');
});

test('a charge posts on the event folio with its tax, and a repeat key returns the same lines', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner);
    $key = 'manual:'.fake()->uuid();

    $result = app(PostEventCharge::class)($owner, $folio, eventCharge($hall->id, '1000.00', $key));
    $again = app(PostEventCharge::class)($owner, $folio, eventCharge($hall->id, '1000.00', $key));

    expect($result->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('folio_id', $folio->id)->count())->toBe(2)
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('1080.00')
        ->and($again->lines)->toHaveCount(2)
        ->and(Activity::query()->where('event', 'folio.charge_posted')->where('subject_type', $event->getMorphClass())->where('subject_id', $event->id)->count())->toBe(1);
});

test('an event charge is refused on a reservation folio and on a closed event folio', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall] = eventSetup();
    [, $folio] = makeEvent($property, $owner);
    $reservationFolio = Folio::factory()->create(['property_id' => $property->id]);

    expect(app(PostEventCharge::class)($owner, $reservationFolio, eventCharge($hall->id))->refusal)->toBe(PostingResult::REFUSAL_CHARGE_NOT_FOUND);

    $folio->forceFill(['status' => FolioStatus::Closed, 'closed_at' => now()])->save();

    expect(app(PostEventCharge::class)($owner, $folio, eventCharge($hall->id))->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('a deposit before the event is an Advance on the event folio, and a security deposit is refused', function () {
    ['property' => $property, 'owner' => $owner, 'cash' => $cash] = eventSetup();
    [, $folio] = makeEvent($property, $owner);

    $result = app(RecordPayment::class)->forEvent($owner, $folio, eventPayment($cash));
    $refused = app(RecordPayment::class)->forEvent($owner, $folio, eventPayment($cash, ['purpose' => PaymentPurpose::SecurityDeposit]));

    expect($result->isRefused())->toBeFalse()
        ->and($result->payment->folio_id)->toBe($folio->id)
        ->and($result->payment->purpose)->toBe(PaymentPurpose::Advance)
        ->and($result->payment->receipt_number)->not->toBeNull()
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('-500.00')
        ->and($refused->refusal)->toBe(PaymentResult::REFUSAL_PURPOSE_NOT_OFFERED)
        ->and(Payment::query()->count())->toBe(1);
});

test('an event payment refuses OTA collected and Old system methods', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [, $folio] = makeEvent($property, $owner);
    $ota = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::OtaCollected, 'requires_reference' => false]);

    expect(app(RecordPayment::class)->forEvent($owner, $folio, eventPayment($ota))->refusal)->toBe(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE)
        ->and(FolioLine::query()->where('kind', TransactionKind::Payment)->count())->toBe(0);
});

test('closing the event folio at 0.00 completes the event, and reopening it confirms it again', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall, 'cash' => $cash] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner);
    app(PostEventCharge::class)($owner, $folio, eventCharge($hall->id));
    app(RecordPayment::class)->forEvent($owner, $folio, eventPayment($cash, ['amount' => '1080.00']));

    $closed = app(CloseFolio::class)($owner, $folio);

    expect($closed->isRefused())->toBeFalse()
        ->and($event->refresh()->status)->toBe(EventStatus::Completed)
        ->and(Activity::query()->where('event', 'folio.closed')->where('subject_id', $event->id)->where('subject_type', $event->getMorphClass())->exists())->toBeTrue();

    app(ReopenFolio::class)($owner, $folio->refresh());

    expect($event->refresh()->status)->toBe(EventStatus::Confirmed);
});

test('closing the folio of a cancelled event keeps it Cancelled', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner);
    app(CancelEvent::class)($owner, $event);

    app(CloseFolio::class)($owner, $folio);

    expect($folio->refresh()->status)->toBe(FolioStatus::Closed)
        ->and($event->refresh()->status)->toBe(EventStatus::Cancelled);
});

test('a company event folio settles to the City ledger', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall] = eventSetup();
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);
    $company = Company::factory()->create(['property_id' => $property->id]);
    [$event, $folio] = makeEvent($property, $owner, ['payer_guest_id' => null, 'payer_company_id' => $company->id]);
    app(PostEventCharge::class)($owner, $folio, eventCharge($hall->id));

    $result = app(SettleToCityLedger::class)($owner, $folio);

    expect($result->isRefused())->toBeFalse()
        ->and($result->invoice->company_id)->toBe($company->id)
        ->and($result->invoice->amount)->toBe('1080.00')
        ->and($folio->refresh()->status)->toBe(FolioStatus::Closed)
        ->and(Event::query()->findOrFail($event->id)->status)->toBe(EventStatus::Completed);
});
