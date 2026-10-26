<?php

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::ensureDirectoryExists(base_path('tests/Browser/Screenshots/reservations'));
    $this->property = Property::query()->where('name', 'Pearlside View Resort')->firstOrFail();
    $this->actingAs(User::query()->where('email', DemoSeeder::loginEmail('pearlside', DefaultRoleEnum::Owner))->firstOrFail());
});

test('reservation list', function () {
    visit(route('reservations.index', $this->property))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/reservation-list');
});

test('new booking form', function () {
    visit(route('reservations.new', $this->property))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/new-booking');
});

test('group reservation page', function () {
    $group = Reservation::query()
        ->where('property_id', $this->property->id)
        ->withCount('units')
        ->orderByDesc('units_count')
        ->firstOrFail();

    visit(route('reservations.show', [$this->property, $group]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/group-reservation');
});
