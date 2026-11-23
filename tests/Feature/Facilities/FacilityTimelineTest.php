<?php

use App\Enums\FolioType;
use App\Features\Facilities;
use App\Models\BusinessDate;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Facilities\FacilityTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * A property with the Facilities feature on, its owner and one Confirmed event on 20 Oct 2026 with its folio.
 *
 * @return array{property: Property, owner: User, event: Event}
 */
function facilityTimelineSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    Feature::for($property)->activate(Facilities::class);

    return [
        'property' => $property,
        'owner' => $owner,
        'event' => Event::factory()->withFolio()->create(['property_id' => $property->id, 'name' => 'WWF lunch']),
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function timelineBooking(Event $event, Facility $facility, array $attributes = []): FacilityBooking
{
    return FacilityBooking::factory()->create([
        'event_id' => $event->id,
        'property_id' => $event->property_id,
        'facility_id' => $facility->id,
        ...$attributes,
    ]);
}

function timelineWindow(): array
{
    return [CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-10-27')];
}

test('a cell counts the most Confirmed bookings at one time, not the day total', function () {
    ['property' => $property, 'event' => $event] = facilityTimelineSetup();
    $walk = Facility::factory()->byPerson()->create(['property_id' => $property->id, 'name' => 'Night Walk']);
    timelineBooking($event, $walk, ['starts_at' => '10:00', 'ends_at' => '12:00', 'quantity' => 8]);
    timelineBooking($event, $walk, ['starts_at' => '11:00', 'ends_at' => '13:00', 'quantity' => 5]);
    timelineBooking($event, $walk, ['starts_at' => '13:00', 'ends_at' => '15:00', 'quantity' => 10]);
    FacilityBooking::factory()->tentative()->create(['event_id' => $event->id, 'property_id' => $property->id, 'facility_id' => $walk->id, 'starts_at' => '10:00', 'ends_at' => '11:00', 'quantity' => 4]);

    $cells = app(FacilityTimeline::class)->cells($property, ...timelineWindow());

    expect($cells)->toHaveCount(1)
        ->and($cells[0]->facilityId)->toBe($walk->id)
        ->and($cells[0]->date)->toBe('2026-10-20')
        ->and($cells[0]->booked)->toBe(13)
        ->and($cells[0]->full)->toBeFalse()
        ->and($cells[0]->bookings)->toBe(4)
        ->and($cells[0]->tentative)->toBe(1)
        ->and($cells[0]->label)->toBeNull();
});

test('a cell is Full when Confirmed bookings reach How many, and cancelled bookings and other properties are left out', function () {
    ['property' => $property, 'event' => $event] = facilityTimelineSetup();
    $kayak = Facility::factory()->create(['property_id' => $property->id, 'capacity' => 3]);
    timelineBooking($event, $kayak, ['quantity' => 3]);
    timelineBooking($event, $kayak, ['booking_date' => '2026-10-21', 'quantity' => 2])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    FacilityBooking::factory()->create();

    $cells = app(FacilityTimeline::class)->cells($property, ...timelineWindow());

    expect($cells)->toHaveCount(1)
        ->and($cells[0]->booked)->toBe(3)
        ->and($cells[0]->full)->toBeTrue();
});

test('a day with one booking names it: the event, or the booker and reference', function () {
    ['property' => $property, 'event' => $event] = facilityTimelineSetup();
    $hall = Facility::factory()->create(['property_id' => $property->id]);
    $bbq = Facility::factory()->create(['property_id' => $property->id]);
    timelineBooking($event, $hall);
    $reservation = Reservation::factory()->create([
        'property_id' => $property->id,
        'booker_guest_id' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Lee Wei'])->id,
    ]);
    $folio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id, 'type' => FolioType::Master]);
    FacilityBooking::factory()->create([
        'event_id' => null,
        'reservation_id' => $reservation->id,
        'folio_id' => $folio->id,
        'property_id' => $property->id,
        'facility_id' => $bbq->id,
        'booking_date' => '2026-10-21',
    ]);

    $labels = collect(app(FacilityTimeline::class)->cells($property, ...timelineWindow()))->pluck('label', 'facilityId');

    expect($labels[$hall->id])->toBe('WWF lunch')
        ->and($labels[$bbq->id])->toBe('Lee Wei · '.$reservation->reference);
});

test('the rows are the available facilities and any other with a booking in the window, in order', function () {
    ['property' => $property, 'event' => $event] = facilityTimelineSetup();
    $second = Facility::factory()->create(['property_id' => $property->id, 'name' => 'BBQ pit', 'position' => 2]);
    $first = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall', 'position' => 1]);
    $offBooked = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Old boat', 'position' => 3, 'active' => false]);
    Facility::factory()->create(['property_id' => $property->id, 'name' => 'Unused', 'position' => 4, 'active' => false]);
    timelineBooking($event, $offBooked);

    $rows = app(FacilityTimeline::class)->rows($property, ...timelineWindow());

    expect(collect($rows)->pluck('name')->all())->toBe(['Riverside Hall', 'BBQ pit', 'Old boat'])
        ->and($rows[0]->single)->toBeTrue()
        ->and($second->id)->toBe($rows[1]->id)
        ->and($first->id)->toBe($rows[0]->id);
});

test('the timeline sends the facilities only when the feature is on', function () {
    ['property' => $property, 'owner' => $owner, 'event' => $event] = facilityTimelineSetup();
    timelineBooking($event, Facility::factory()->create(['property_id' => $property->id]));

    $this->actingAs($owner)->get(route('front-desk.timeline', $property->code))
        ->assertInertia(fn (Assert $page) => $page->has('facilities', 1)->has('facilityCells', 1)->where('canViewEvents', true));

    Feature::for($property)->deactivate(Facilities::class);

    $this->actingAs($owner)->get(route('front-desk.timeline', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('facilities', null)->where('facilityCells', [])->where('canViewEvents', false));
});

test('the facility bookings add no queries however many there are', function () {
    ['property' => $property, 'owner' => $owner, 'event' => $event] = facilityTimelineSetup();
    $countQueries = function () use ($owner, $property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('front-desk.timeline', $property->code))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    timelineBooking($event, Facility::factory()->create(['property_id' => $property->id]));
    $countQueries();
    $before = $countQueries();

    foreach (range(1, 4) as $index) {
        $other = Event::factory()->withFolio()->create(['property_id' => $property->id, 'starts_on' => '2026-10-2'.$index, 'ends_on' => '2026-10-2'.$index]);
        timelineBooking($other, Facility::factory()->create(['property_id' => $property->id, 'position' => $index + 1]));
    }

    expect($countQueries())->toBe($before);
});

test('the day peek lists the live bookings of the facility on the date', function () {
    ['property' => $property, 'owner' => $owner, 'event' => $event] = facilityTimelineSetup();
    $bbq = Facility::factory()->create(['property_id' => $property->id, 'capacity' => 3]);
    timelineBooking($event, $bbq, ['starts_at' => '19:00', 'ends_at' => '22:00', 'quantity' => 2]);
    timelineBooking($event, $bbq, ['starts_at' => '12:00', 'ends_at' => '14:00']);
    timelineBooking($event, $bbq, ['starts_at' => '08:00', 'ends_at' => '09:00'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    $this->actingAs($owner)
        ->getJson(route('front-desk.facilities.day', [$property->code, $bbq->id, '2026-10-20']))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.startsAt', '12:00')
        ->assertJsonPath('0.name', 'WWF lunch')
        ->assertJsonPath('0.eventId', $event->id)
        ->assertJsonPath('1.quantity', 2)
        ->assertJsonPath('1.quantityUnit', 'items');
});

test('the day peek is not found for another property, a bad date or with the feature off', function () {
    ['property' => $property, 'owner' => $owner] = facilityTimelineSetup();
    $facility = Facility::factory()->create(['property_id' => $property->id]);
    $other = Facility::factory()->create();

    $this->actingAs($owner)->getJson(route('front-desk.facilities.day', [$property->code, $other->id, '2026-10-20']))->assertNotFound();
    $this->actingAs($owner)->getJson(route('front-desk.facilities.day', [$property->code, $facility->id, '2026-02-30']))->assertNotFound();

    Feature::for($property)->deactivate(Facilities::class);

    $this->actingAs($owner)->getJson(route('front-desk.facilities.day', [$property->code, $facility->id, '2026-10-20']))->assertNotFound();
});

test('the day peek needs front-desk.view', function () {
    ['property' => $property] = facilityTimelineSetup();
    $facility = Facility::factory()->create(['property_id' => $property->id]);
    $clerk = User::factory()->for($property)->withPermissions([])->create();

    $this->actingAs($clerk)->getJson(route('front-desk.facilities.day', [$property->code, $facility->id, '2026-10-20']))->assertForbidden();
});
