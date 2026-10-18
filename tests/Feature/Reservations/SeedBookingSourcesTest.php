<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Reservations\SeedBookingSources;
use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\PropertyType;
use App\Enums\SwatchColor;
use App\Models\Property;

test('a property gets Phone, WhatsApp, Email and the two system sources', function () {
    $property = Property::factory()->create();

    app(SeedBookingSources::class)($property);

    $sources = $property->bookingSources()->ordered()->get();

    expect($sources->pluck('name')->all())->toBe(['Phone', 'WhatsApp', 'Email', 'Company / Travel agent', 'Walk-in'])
        ->and($sources->pluck('type')->all())->toBe([BookingSourceType::Direct, BookingSourceType::Direct, BookingSourceType::Direct, BookingSourceType::Company, BookingSourceType::WalkIn])
        ->and($sources->pluck('is_system')->all())->toBe([false, false, false, true, true])
        ->and($sources->pluck('badge_icon')->all())->toBe([BookingSourceIcon::Phone, BookingSourceIcon::MessageCircle, BookingSourceIcon::Mail, BookingSourceIcon::BriefcaseBusiness, BookingSourceIcon::Footprints])
        ->and($sources->pluck('badge_color')->all())->toBe([SwatchColor::Gray, SwatchColor::Green, SwatchColor::Blue, SwatchColor::Amber, SwatchColor::Teal])
        ->and($sources->every(fn ($source) => $source->is_active))->toBeTrue();
});

test('SeedPropertyDefaults seeds the booking sources', function () {
    $property = Property::factory()->create();

    app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');

    expect($property->bookingSources()->count())->toBe(5);
});

test('seeding twice keeps the property changes and adds nothing', function () {
    $property = Property::factory()->create();
    $other = Property::factory()->create();
    $seed = app(SeedBookingSources::class);

    $seed($property);
    $property->bookingSources()->where('name', 'Phone')->update(['name' => 'Front desk', 'badge_color' => 'red']);
    $property->bookingSources()->where('name', 'Email')->delete();
    $seed($property);

    expect($property->bookingSources()->count())->toBe(4)
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
        ->and($property->bookingSources()->count())->toBe(5);
});
