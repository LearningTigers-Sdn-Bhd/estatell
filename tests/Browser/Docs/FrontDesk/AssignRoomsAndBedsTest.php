<?php

use App\Models\UnitNight;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $this->property->settings->update(['auto_assign_rooms' => false]);

    $this->unassigned = $this->scenario('S13');
    UnitNight::query()->whereIn('reservation_unit_id', $this->unassigned->units()->pluck('id'))->update(['room_id' => null]);
});

test('a unit with no room', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->unassigned]))
        ->click('[aria-label="Unit actions"]')
        ->wait(1);

    $this->shoot($page, 'not-assigned', badges: ['Move room']);

    $page->click('Move room')
        ->waitForText('Assign room')
        ->click('Choose a room')
        ->click('[role="option"] >> nth=0')
        ->wait(1);

    $this->shoot($page, 'assign-room', frame: '[role="dialog"]');
});

test('the unassigned lane on the timeline', function () {
    $page = $this->open(route('front-desk.timeline', $this->property))
        ->waitForText('Unassigned')
        ->wait(1);

    $this->shoot($page, 'unassigned-lane', badges: ['Unassigned · Standard Double']);
});
