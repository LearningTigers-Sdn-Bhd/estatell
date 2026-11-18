<?php

use App\Enums\BookingEngineMode;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Features\BookingEngine;
use App\Models\ArInvoice;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use Database\Seeders\AmenitySeeder;
use Database\Seeders\VarsityLoftSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['seeding.boreno_password' => 'varsity-pitch-2026', 'estatell.demo.password' => null]);
    $this->seed(AmenitySeeder::class);
});

test('the seeder builds Varsity Loft as a live student hostel with sample data and its own staff logins', function () {
    $this->seed(VarsityLoftSeeder::class);

    $property = Property::query()->where('name', 'Varsity Loft')->sole();
    $owner = User::query()->where('email', 'owner@varsityloft.com')->sole();
    $invoice = ArInvoice::query()->where('property_id', $property->id)->sole();
    $intake = Reservation::query()->where('property_id', $property->id)->where('group_name', 'UMS Faculty of Engineering intake')->sole();

    expect($property->status)->toBe(PropertyStatus::Live)
        ->and($property->type)->toBe(PropertyType::Hostel)
        ->and($owner->property_id)->toBe($property->id)
        ->and(Hash::check('varsity-pitch-2026', $owner->password))->toBeTrue()
        ->and(User::query()->where('property_id', $property->id)->orderBy('email')->pluck('email')->all())
        ->toBe(['frontdesk@varsityloft.com', 'housekeeping@varsityloft.com', 'manager@varsityloft.com', 'owner@varsityloft.com'])
        ->and(Room::query()->where('property_id', $property->id)->count())->toBe(20)
        ->and($property->beds()->count())->toBe(40)
        ->and(Reservation::query()->where('property_id', $property->id)->count())->toBe(16)
        ->and(ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::CheckedIn)->count())->toBe(3)
        ->and($intake->units()->count())->toBe(8)
        ->and(RoomBlock::query()->where('property_id', $property->id)->whereNotNull('bed_id')->count())->toBe(1)
        ->and(RatePlan::query()->where('property_id', $property->id)->withCount('stayDiscounts')->pluck('stay_discounts_count')->unique()->all())->toBe([2])
        ->and($invoice->company->name)->toBe('Yayasan Sabah')
        ->and(app(ArInvoiceBalance::class)->forInvoice($invoice))->toBe(bcsub((string) $invoice->amount, '50.00', 2));
});

test('the seeder leaves an existing Varsity Loft and its password unchanged', function () {
    $this->seed(VarsityLoftSeeder::class);
    config(['seeding.boreno_password' => 'different-password']);

    $this->seed(VarsityLoftSeeder::class);

    $this->assertDatabaseCount('properties', 1);
    $this->assertDatabaseCount('users', 4);
    expect(Hash::check('varsity-pitch-2026', User::query()->where('email', 'owner@varsityloft.com')->sole()->password))->toBeTrue();
});

test('the seeder refuses an invalid password without leaving property data', function (?string $password) {
    config(['seeding.boreno_password' => $password]);

    expect(fn () => $this->seed(VarsityLoftSeeder::class))->toThrow(RuntimeException::class, 'BORENO_SEED_PASSWORD');

    $this->assertDatabaseCount('properties', 0);
    $this->assertDatabaseCount('users', 0);
})->with([null, '', 'short']);

test('the seeder sets up the booking site with About, location and amenities, but does not publish it', function () {
    $this->seed(VarsityLoftSeeder::class);

    $property = Property::query()->where('name', 'Varsity Loft')->sole();
    $site = $property->bookingSite()->sole();
    $studio = $property->roomTypes()->where('name', 'Studio')->sole();

    expect(Feature::for($property)->active(BookingEngine::class))->toBeTrue()
        ->and($site->mode)->toBe(BookingEngineMode::Microsite)
        ->and($site->isPublished())->toBeFalse()
        ->and($property->about)->toContain('Universiti Malaysia Sabah')
        ->and($property->slug)->toBe('varsity-loft')
        ->and($property->latitude)->not->toBeNull()
        ->and(RatePlan::query()->where('property_id', $property->id)->bookableOnline()->count())->toBe(2)
        ->and($property->amenities()->count())->toBe(31)
        ->and($studio->amenities()->pluck('name')->all())->toContain('Kitchenette', 'Private bathroom');
});

test('the seeder refuses to run without the platform amenity list', function () {
    DB::table('amenities')->delete();

    expect(fn () => $this->seed(VarsityLoftSeeder::class))->toThrow(RuntimeException::class, 'Run AmenitySeeder first');

    $this->assertDatabaseCount('properties', 0);
});
