<?php

use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $room = fn (string $number): Room => Room::query()->where('property_id', $this->property->id)->where('number', $number)->firstOrFail();

    RoomBlock::factory()->create([
        'room_id' => $room('104')->id,
        'starts_on' => '2026-10-16',
        'ends_on' => '2026-10-18',
    ]);

    $dayUseRoom = $room('103');
    $reservation = Reservation::factory()->create(['property_id' => $this->property->id]);
    ReservationUnit::factory()->dayUse('2026-10-16', '10:00', 4, $dayUseRoom->id)->create([
        'reservation_id' => $reservation->id,
        'room_type_id' => $dayUseRoom->room_type_id,
        'rate_plan_id' => RatePlan::query()->where('property_id', $this->property->id)->orderBy('position')->value('id'),
    ]);
});

test('the timeline view', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->wait(1);

    $this->shoot($page, 'timeline',
        badges: ['Filters', 'Room group', 'Nur Izzati binti Azman', 'Sabah Teachers Union · Huang Jing', 'Maintenance', 'Available · Occupancy'],
    );
});

test('the hover card of a stay', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->hover('button[aria-label^="Nur Izzati"]')
        ->wait(1.5);

    $this->shoot($page, 'timeline-hover-card');
});

test('the hover card of a day use', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->hover('[aria-label^="Day use"]')
        ->wait(1.5);

    $this->shoot($page, 'timeline-day-use');
});

test('the peek sheet', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->click('button[aria-label^="Huang Jing"]')
        ->waitForText('Open group booking')
        ->wait(1.5);

    $this->shoot($page, 'timeline-peek', frame: '[role="dialog"]');
});

test('the filters sheet', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->click('Filters')
        ->waitForText('Clear filters')
        ->wait(1);

    $this->shoot($page, 'timeline-filters', frame: '[role="dialog"]');
});

test('the nightly rate row', function () {
    $roomType = RoomType::query()->where('property_id', $this->property->id)->where('name', 'Deluxe Twin')->firstOrFail();

    $page = $this->open(route('front-desk.timeline', [$this->property, 'types' => [$roomType->id]]), height: 700)
        ->waitForText('Nightly rate')
        ->wait(1);

    $this->shoot($page, 'timeline-nightly-rate', badges: ['Nightly rate · MYR']);
});

test('the legend', function () {
    $page = $this->open(route('front-desk.timeline', $this->property), height: 1100)
        ->waitForText('Available · Occupancy')
        ->click('[aria-label="Legend"]')
        ->waitForText('Part of a group booking')
        ->wait(1);

    $this->shoot($page, 'timeline-legend', frame: '[data-slot="popover-content"]');
});
