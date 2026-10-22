<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Payments\RecordPayment;
use App\Actions\Reservations\CreateReservation;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\NewReservationData;
use App\Enums\DocumentType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\BusinessDate;
use App\Models\DocumentSequence;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\NightAudit\NextDocumentNumber;
use App\Services\NightAudit\PlannedDocumentNumbers;
use App\Services\NightAudit\PlannedNumberRefused;
use Database\Factories\BookingSourceFactory;

function plannedProperty(): Property
{
    $property = Property::factory()->live()->create(['name' => 'Grand River Hotel']);
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);

    return $property;
}

test('a queued number is issued in order and the desk carries on after it', function () {
    $property = plannedProperty();
    $queue = app(PlannedDocumentNumbers::class);
    $queue->push(DocumentType::Invoice, 120);
    $queue->push(DocumentType::Invoice, 125);
    $queue->push(DocumentType::Receipt, 7);
    $next = app(NextDocumentNumber::class);

    expect($next($property, DocumentType::Invoice)->number)->toBe(120)
        ->and($next($property, DocumentType::Invoice)->number)->toBe(125)
        ->and($next($property, DocumentType::Invoice)->number)->toBe(126)
        ->and($next($property, DocumentType::Receipt)->reference)->toBe('GRH-26500007')
        ->and($queue->pending())->toBe([]);
});

test('an empty queue numbers as before', function () {
    $property = plannedProperty();

    expect(app(NextDocumentNumber::class)($property, DocumentType::Invoice)->number)->toBe(1)
        ->and(app(PlannedDocumentNumbers::class)->pending())->toBe([]);
});

test('a queued number that is not above the last number is refused and writes nothing', function () {
    $property = plannedProperty();
    $next = app(NextDocumentNumber::class);
    $next($property, DocumentType::Invoice, planned: 50);
    app(PlannedDocumentNumbers::class)->push(DocumentType::Invoice, 40);

    expect(fn () => $next($property, DocumentType::Invoice))->toThrow(PlannedNumberRefused::class)
        ->and(DocumentSequence::query()->where('property_id', $property->id)->where('type', 'invoice')->value('last_number'))->toBe(50);
});

test('CreateReservation issues a queued reservation number', function () {
    $property = plannedProperty();
    $plan = RatePlan::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $plan->roomTypes()->attach($roomType);
    foreach (['2026-10-15', '2026-10-16'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => '100.00']);
    }
    app(PlannedDocumentNumbers::class)->push(DocumentType::Reservation, 321);

    $result = app(CreateReservation::class)(User::factory()->for($property)->create(), $property, NewReservationData::from([
        'bookerGuestId' => Guest::factory()->create(['property_id' => $property->id])->id,
        'bookingSource' => BookingSourceFactory::directFor($property->id),
        'units' => [['roomTypeId' => $roomType->id, 'ratePlanId' => $plan->id, 'arrivalDate' => '2026-10-15', 'departureDate' => '2026-10-17', 'adults' => 2]],
    ]));

    expect($result->isRefused())->toBeFalse()
        ->and($result->reservation->reference)->toBe('GRH-26100321');
});

test('RecordPayment issues a queued receipt number', function () {
    $property = plannedProperty();
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->create(['reservation_id' => $reservation->id]);
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);
    app(PlannedDocumentNumbers::class)->push(DocumentType::Receipt, 710);

    $result = app(RecordPayment::class)(User::factory()->for($property)->create(), $reservation, null, $unit, NewPaymentData::from([
        'paymentMethodId' => $cash->id,
        'purpose' => PaymentPurpose::Advance,
        'amount' => '100.00',
        'reference' => null,
        'idempotencyKey' => fake()->uuid(),
    ]));

    expect($result->payment->receipt_number)->toBe('GRH-26500710');
});

test('CloseFolio issues a queued invoice number', function () {
    $property = plannedProperty();
    $actor = User::factory()->for($property)->create();
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => Reservation::factory()->create(['property_id' => $property->id])->id]);
    $folio = Folio::factory()->forUnit($unit)->create(['property_id' => $property->id]);
    app(PlannedDocumentNumbers::class)->push(DocumentType::Invoice, 670);

    $result = app(CloseFolio::class)($actor, $folio);

    expect($result->isRefused())->toBeFalse()
        ->and($folio->refresh()->invoice_number)->toBe('GRH-26700670');
});
