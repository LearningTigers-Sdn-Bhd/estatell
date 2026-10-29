<?php

use App\Actions\Onboarding\SaveOnboardingRoomTypes;
use App\Enums\SellUnit;
use App\Models\Property;
use Pest\Browser\Api\AwaitableWebpage;

beforeEach(function () {
    $this->startOnboarding();
});

/**
 * Save the room types of the Rooms screenshots: "Standard Queen" and "Twin", plus "6-Bed Mixed Dorm" sold by
 * bed when asked.
 */
function saveRoomsStepTypes(Property $property, bool $withDorm = false): void
{
    $rows = [
        ['id' => null, 'name' => 'Standard Queen', 'sell_unit' => SellUnit::Room, 'max_adults' => 2, 'max_children' => 1],
        ['id' => null, 'name' => 'Twin', 'sell_unit' => SellUnit::Room, 'max_adults' => 2, 'max_children' => 0],
    ];

    if ($withDorm) {
        $rows[] = ['id' => null, 'name' => '6-Bed Mixed Dorm', 'sell_unit' => SellUnit::Bed, 'max_adults' => 1, 'max_children' => 0];
    }

    app(SaveOnboardingRoomTypes::class)($property, auth()->user(), $rows);
}

/**
 * Pick the room type tile with this name.
 */
function pickRoomType(AwaitableWebpage $page, string $name): void
{
    $page->page()->locator("label[for^=\"room-type-\"]:has-text(\"{$name}\")")->click();
}

/**
 * Click a room on the grid, with Shift held for a run.
 */
function clickRoom(AwaitableWebpage $page, string $number, bool $shift = false): void
{
    $page->page()->locator("button[aria-label^=\"Room {$number},\"]")->click($shift ? ['modifiers' => ['Shift']] : null);
}

test('room types', function () {
    $this->saveOnboardingBasics();

    $page = $this->open(route('onboarding.room-types.edit', $this->property->code), height: 800)
        ->waitForText('What kinds of rooms do you sell?')
        ->type('[aria-label="Name of room type 1"]', 'Standard Double')
        ->type('[aria-label="Max children of room type 1"]', '1')
        ->click('Add room type')
        ->type('[aria-label="Name of room type 2"]', 'Deluxe King');

    $this->shoot($page, 'room-types');
});

test('room numbers with no groups', function () {
    $this->saveOnboardingBasics();
    saveRoomsStepTypes($this->property);

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 900)
        ->waitForText('Which rooms do you have, and what type is each one?')
        ->type('[aria-label="Room numbers of all rooms"]', '101-108')
        ->waitForText('Total: 8 rooms');

    $this->shoot($page, 'rooms');
});

test('room numbers with groups', function () {
    $this->saveOnboardingBasics();
    saveRoomsStepTypes($this->property);

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 900)
        ->waitForText('Which rooms do you have, and what type is each one?')
        ->click('Use groups')
        ->type('[aria-label="Name of group 1"]', 'Floor 1')
        ->type('[aria-label="Room numbers of Floor 1"]', '101-110')
        ->click('Add group')
        ->type('[aria-label="Name of group 2"]', 'Floor 2')
        ->type('[aria-label="Room numbers of Floor 2"]', '201-204, 206-210')
        ->waitForText('Total: 19 rooms');

    $this->shoot($page, 'rooms-groups', element: '[aria-labelledby="room-numbers"]');
});

test('room types on the grid', function () {
    $this->saveOnboardingBasics();
    saveRoomsStepTypes($this->property);

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 1000)
        ->waitForText('Which rooms do you have, and what type is each one?')
        ->click('Use groups')
        ->type('[aria-label="Name of group 1"]', 'Floor 1')
        ->type('[aria-label="Room numbers of Floor 1"]', '101-110')
        ->click('Add group')
        ->type('[aria-label="Name of group 2"]', 'Floor 2')
        ->type('[aria-label="Room numbers of Floor 2"]', '201-204, 206-210')
        ->waitForText('Total: 19 rooms');

    pickRoomType($page, 'Twin');
    clickRoom($page, '102');
    clickRoom($page, '103', shift: true);
    clickRoom($page, '106');
    clickRoom($page, '207');
    clickRoom($page, '209', shift: true);
    $page->hover('#room-types');

    $this->shoot($page, 'rooms-grid', element: '[aria-labelledby="room-types"]');
});

test('dorm beds per room', function () {
    $this->saveOnboardingBasics();
    saveRoomsStepTypes($this->property, withDorm: true);

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 1000)
        ->waitForText('Which rooms do you have, and what type is each one?')
        ->type('[aria-label="Room numbers of all rooms"]', '101-106, 301-302')
        ->waitForText('Total: 8 rooms');

    pickRoomType($page, '6-Bed Mixed Dorm');
    clickRoom($page, '301');
    clickRoom($page, '302', shift: true);
    $page->waitForText('Beds per room')
        ->clear('[id^="beds-per-room-"]')
        ->type('[id^="beds-per-room-"]', '6')
        ->waitForText('6 beds')
        ->hover('#room-types');

    $this->shoot($page, 'rooms-dorm', element: '[aria-labelledby="room-types"]');
});

test('room numbers that run backwards', function () {
    $this->saveOnboardingBasics();
    saveRoomsStepTypes($this->property);

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 900)
        ->waitForText('Which rooms do you have, and what type is each one?')
        ->type('[aria-label="Room numbers of all rooms"]', '101-108, 120-110')
        ->waitForText('runs backwards');

    $this->shoot($page, 'rooms-error', element: '[aria-labelledby="room-numbers"]');
});

test('archive removed rooms', function () {
    $this->saveOnboardingRooms($this->saveOnboardingRoomType());

    $page = $this->open(route('onboarding.rooms.edit', $this->property->code), height: 900)
        ->waitForText('Total: 5 rooms')
        ->clear('[aria-label="Room numbers of Garden block"]')
        ->type('[aria-label="Room numbers of Garden block"]', 'G1')
        ->waitForText('Total: 4 rooms')
        ->click('Save & continue')
        ->waitForText('Save archives 1 room: G2.');

    $this->shoot($page, 'rooms-archive', frame: '[role="alertdialog"]');
});
