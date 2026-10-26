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

test('new menu', function () {
    visit(route('reservations.index', $this->property))
        ->resize(1440, 900)
        ->click('New')
        ->assertSee('Walk-in')
        ->screenshot(filename: 'reservations/new-menu');
});

test('new booking form', function () {
    visit(route('reservations.new', $this->property))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/new-booking');
});

test('new booking form with a priced line', function () {
    visit(route('reservations.new', $this->property))
        ->resize(1440, 900)
        ->click('Pick a date')
        ->click('[role="dialog"] button:text-is("16")')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('Select rate plan')
        ->click('Bed & Breakfast')
        ->waitForText('MYR 480.00')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/new-booking-priced');
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
