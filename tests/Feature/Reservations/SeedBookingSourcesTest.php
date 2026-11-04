<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Reservations\SeedBookingSources;
use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\PropertyType;
use App\Enums\SwatchColor;
use App\Models\Property;

test('a property gets Phone, WhatsApp, Email and the three system sources', function () {
    $property = Property::factory()->create();

    app(SeedBookingSources::class)($property);

    $sources = $property->bookingSources()->ordered()->get();

    expect($sources->pluck('name')->all())->toBe(['Phone', 'WhatsApp', 'Email', 'Company / Travel agent', 'Walk-in', 'Booking Engine'])
        ->and($sources->pluck('type')->all())->toBe([BookingSourceType::Direct, BookingSourceType::Direct, BookingSourceType::Direct, BookingSourceType::Company, BookingSourceType::WalkIn, BookingSourceType::BookingEngine])
        ->and($sources->pluck('is_system')->all())->toBe([false, false, false, true, true, true])
        ->and($sources->pluck('badge_icon')->all())->toBe([BookingSourceIcon::Phone, BookingSourceIcon::MessageCircle, BookingSourceIcon::Mail, BookingSourceIcon::BriefcaseBusiness, BookingSourceIcon::Footprints, BookingSourceIcon::Globe])
        ->and($sources->pluck('badge_color')->all())->toBe([SwatchColor::Gray, SwatchColor::Green, SwatchColor::Blue, SwatchColor::Amber, SwatchColor::Teal, SwatchColor::Violet])
        ->and($sources->every(fn ($source) => $source->is_active))->toBeTrue();
});

test('SeedPropertyDefaults seeds the booking sources', function () {
    $property = Property::factory()->create();

    app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');

    expect($property->bookingSources()->count())->toBe(6);
});

test('seeding twice keeps the property changes and adds nothing', function () {
    $property = Property::factory()->create();
    $other = Property::factory()->create();
    $seed = app(SeedBookingSources::class);

    $seed($property);
    $property->bookingSources()->where('name', 'Phone')->update(['name' => 'Front desk', 'badge_color' => 'red']);
    $property->bookingSources()->where('name', 'Email')->delete();
    $seed($property);

    expect($property->bookingSources()->count())->toBe(5)
        ->and($property->bookingSources()->where('name', 'Front desk')->sole()->badge_color)->toBe(SwatchColor::Red)
        ->and($property->bookingSources()->where('name', 'Phone')->exists())->toBeFalse()
        ->and($other->bookingSources()->count())->toBe(0);
});

test('a missing system source comes back by type', function () {
    $property = Property::factory()->create();
    $seed = app(SeedBookingSources::class);

    $seed($property);
    $property->bookingSources()->where('type', BookingSourceType::WalkIn)->delete();
    $seed($property);

    expect($property->bookingSources()->where('type', BookingSourceType::WalkIn)->where('is_system', true)->count())->toBe(1)
        ->and($property->bookingSources()->count())->toBe(6);
});

test('a source already named Booking Engine stays, and the system row is named with (Online)', function () {
    $property = Property::factory()->create();
    $property->bookingSources()->forceCreate(['name' => 'booking engine', 'type' => BookingSourceType::Direct, 'sort_order' => 1, 'badge_icon' => BookingSourceIcon::Globe, 'badge_color' => SwatchColor::Gray]);

    app(SeedBookingSources::class)($property);
    app(SeedBookingSources::class)($property);

    expect($property->bookingSources()->where('type', BookingSourceType::Direct)->sole()->name)->toBe('booking engine')
        ->and($property->bookingSources()->where('type', BookingSourceType::BookingEngine)->sole()->name)->toBe('Booking Engine (Online)');
});
