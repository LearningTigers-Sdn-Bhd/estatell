<?php

use App\Actions\Events\CreateEvent;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Enums\PermissionEnum;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Tax;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * @return array{name: string, payer_guest_id: int, payer_company_id: null, starts_on: string, ends_on: string, status: EventStatus}
 */
function newEventAttributes(Property $property, EventStatus $status = EventStatus::Confirmed): array
{
    return [
        'name' => 'Lim & Tan wedding dinner',
        'payer_guest_id' => Guest::factory()->create(['property_id' => $property->id])->id,
        'payer_company_id' => null,
        'starts_on' => '2026-10-20',
        'ends_on' => '2026-10-20',
        'status' => $status,
    ];
}

function hallLine(Facility $hall, string $from, string $to): FacilityBookingInputData
{
    return new FacilityBookingInputData($hall->id, '2026-10-20', $from, $to, 1, null, null);
}

test('New event saves the event, its folio and its lines together, with the event status', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = Facility::factory()->create(['property_id' => $property->id]);
    $bbq = Facility::factory()->create(['property_id' => $property->id]);

    $result = app(CreateEvent::class)->withBookings($property, $owner, newEventAttributes($property, EventStatus::Tentative), [
        hallLine($hall, '18:00', '23:00'),
        hallLine($bbq, '12:00', '14:00'),
    ]);

    expect($result->isRefused())->toBeFalse()
        ->and($result->event->facilityBookings()->pluck('status')->all())->toBe([FacilityBookingStatus::Tentative, FacilityBookingStatus::Tentative])
        ->and($result->event->facilityBookings()->pluck('folio_id')->unique()->all())->toBe([$result->event->folios()->sole()->id]);
});

test('a refused line saves nothing, and a later line is checked against the earlier ones', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall']);

    $result = app(CreateEvent::class)->withBookings($property, $owner, newEventAttributes($property), [
        hallLine($hall, '18:00', '23:00'),
        hallLine($hall, '20:00', '22:00'),
    ]);

    expect($result->line)->toBe(1)
        ->and($result->refusal->refusal)->toBe('facility_clash')
        ->and(Event::query()->count())->toBe(0)
        ->and(FacilityBooking::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'events.created')->exists())->toBeFalse();
});

test('the page sends a refused line back as an error on that line', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall']);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $line = ['facility_id' => $hall->id, 'booking_date' => '2026-10-20', 'starts_at' => '18:00', 'ends_at' => '23:00', 'quantity' => 1, 'unit_amount' => '1200.00'];
    $payload = ['name' => 'Wedding', 'payer_type' => 'guest', 'guest' => ['guest_id' => $guest->id], 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'status' => 'confirmed'];

    $this->actingAs($owner)->post(route('events.store', $property->code), [...$payload, 'bookings' => [$line, [...$line, 'starts_at' => '22:00']]])
        ->assertSessionHasErrors(['bookings.1.starts_at' => 'Riverside Hall is already booked at that time.']);

    $this->actingAs($owner)->post(route('events.store', $property->code), [...$payload, 'bookings' => [[...$line, 'booking_date' => '2026-10-21']]])
        ->assertSessionHasErrors(['bookings.0.booking_date' => 'The date must be within the event\'s dates.']);

    $response = $this->actingAs($owner)->post(route('events.store', $property->code), [...$payload, 'bookings' => [$line]]);

    $response->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Event '.Event::query()->sole()->number.' created with 1 facility booking.');

    expect(FacilityBooking::query()->sole()->unit_amount)->toBe('1200.00');
});

test('staff without facilities.book get no lines and cannot send them', function () {
    ['property' => $property] = eventSetup();
    $hall = Facility::factory()->create(['property_id' => $property->id]);
    $clerk = User::factory()->for($property)->withPermissions([PermissionEnum::EventsView, PermissionEnum::EventsManage])->create();
    $guest = Guest::factory()->create(['property_id' => $property->id]);

    $this->actingAs($clerk)->get(route('events.create', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('canBook', false)->where('facilities', []));

    $this->actingAs($clerk)->post(route('events.store', $property->code), [
        'name' => 'Wedding', 'payer_type' => 'guest', 'guest' => ['guest_id' => $guest->id], 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'status' => 'confirmed',
        'bookings' => [['facility_id' => $hall->id, 'booking_date' => '2026-10-20', 'starts_at' => '18:00', 'ends_at' => '23:00', 'quantity' => 1]],
    ])->assertSessionHasErrors('bookings');

    $this->actingAs($clerk)->postJson(route('events.quote', $property->code), [])->assertForbidden();

    expect(Event::query()->count())->toBe(0);
});

test('the quote checks each line against the earlier ones and totals the lines', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall']);
    $tax = Tax::factory()->for($property)->create(['name' => 'SST', 'rate' => '8.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach($hall->chargeItem->transaction_code_id);
    $line = ['facility_id' => $hall->id, 'booking_date' => '2026-10-20', 'starts_at' => '09:00', 'ends_at' => '12:00', 'quantity' => 1, 'unit_amount' => '1000.00'];
    $quote = fn (string $status) => $this->actingAs($owner)->postJson(route('events.quote', $property->code), [
        'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'status' => $status,
        'bookings' => [$line, [...$line, 'starts_at' => '11:00', 'ends_at' => '13:00'], [...$line, 'facility_id' => null]],
    ]);

    $quote('confirmed')
        ->assertOk()
        ->assertJsonPath('lines.0.refusal', null)
        ->assertJsonPath('lines.1.refusal', 'Riverside Hall is already booked at that time.')
        ->assertJsonPath('lines.2.amount', null)
        ->assertJsonPath('amount', '2000.00')
        ->assertJsonPath('tax', '160.00')
        ->assertJsonPath('total', '2160.00');

    $quote('tentative')->assertJsonPath('lines.1.refusal', null);
});
