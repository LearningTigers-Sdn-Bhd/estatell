<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

test('a form opened from the Timeline goes back to the same window and filters', function () {
    $setup = bookingSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['user'])
        ->get(route('reservations.new', [
            'property' => $code,
            'back' => 'timeline',
            'back_query' => 'from=2026-10-12&to=2026-10-25&groups%5B%5D=3&q=Tan&group_by=room_type&evil=https%3A%2F%2Fexample.com',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('back.href', route('front-desk.timeline', ['property' => $code, 'from' => '2026-10-12', 'to' => '2026-10-25', 'q' => 'Tan', 'group_by' => 'room_type', 'groups' => ['3']]))
            ->where('back.label', 'Timeline View')
            ->where('back.query', ['back' => 'timeline', 'back_query' => 'from=2026-10-12&to=2026-10-25&q=Tan&group_by=room_type&groups%5B0%5D=3']));
});

test('a form opened from the Room View goes back to the same date and filters', function () {
    $setup = bookingSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['user'])
        ->get(route('reservations.new', [
            'property' => $code,
            'back' => 'rooms',
            'back_query' => 'date=2026-10-12&groups%5B%5D=3&show%5B%5D=vacant&q=Tan&group_by=room_type&from=2026-10-01&evil=https%3A%2F%2Fexample.com',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('back.href', route('front-desk.rooms', ['property' => $code, 'date' => '2026-10-12', 'q' => 'Tan', 'group_by' => 'room_type', 'groups' => ['3'], 'show' => ['vacant']]))
            ->where('back.label', 'Room View')
            ->where('back.query', ['back' => 'rooms', 'back_query' => 'date=2026-10-12&q=Tan&group_by=room_type&groups%5B0%5D=3&show%5B0%5D=vacant']));
});

test('every booking type goes back to the Dashboard when it opened from there', function (string $route) {
    $setup = bookingSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['user'])
        ->get(route($route, ['property' => $code, 'back' => 'dashboard']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('back.href', route('dashboard', ['property' => $code]))
            ->where('back.label', 'Dashboard')
            ->where('back.query', ['back' => 'dashboard']));
})->with(['reservations.new', 'reservations.walk-in', 'reservations.past']);

test('no back, an unknown name or a URL goes back to Reservations', function (array $query) {
    $setup = bookingSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['user'])
        ->get(route('reservations.new', ['property' => $code, ...$query]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('back.href', route('reservations.index', ['property' => $code]))
            ->where('back.label', 'Reservations')
            ->where('back.query', []));
})->with([
    'none' => [[]],
    'unknown' => [['back' => 'settings']],
    'a URL' => [['back' => 'https://example.com']],
]);
