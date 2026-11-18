<?php

use App\Enums\BookingEngineMode;
use App\Enums\PricingBasis;
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
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use Database\Seeders\AmenitySeeder;
use Database\Seeders\RimbaLodgeSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['seeding.boreno_password' => 'rimba-pitch-2026', 'estatell.demo.password' => null]);
    $this->seed(AmenitySeeder::class);
});

test('the seeder builds Rimba Kinabatangan Lodge as a live rainforest lodge with packages and its own staff logins', function () {
    $this->seed(RimbaLodgeSeeder::class);

    $property = Property::query()->where('name', 'Rimba Kinabatangan Lodge')->sole();
    $owner = User::query()->where('email', 'owner@rimbalodge.com')->sole();
    $packages = RatePlan::query()->where('property_id', $property->id)->where('pricing_basis', PricingBasis::Package)->orderBy('fixed_nights')->get();
    $tour = Reservation::query()->where('property_id', $property->id)->where('group_name', 'Borneo Nature Tours: Dutch birding group')->sole();
    $invoice = ArInvoice::query()->where('property_id', $property->id)->sole();

    expect($property->status)->toBe(PropertyStatus::Live)
        ->and($property->type)->toBe(PropertyType::Resort)
        ->and(Hash::check('rimba-pitch-2026', $owner->password))->toBeTrue()
        ->and(User::query()->where('property_id', $property->id)->orderBy('email')->pluck('email')->all())
        ->toBe(['frontdesk@rimbalodge.com', 'housekeeping@rimbalodge.com', 'manager@rimbalodge.com', 'owner@rimbalodge.com'])
        ->and(Room::query()->where('property_id', $property->id)->count())->toBe(15)
        ->and($property->beds()->count())->toBe(6)
        ->and($packages->pluck('name')->all())->toBe(['2D1N Rainforest Package', '3D2N Wildlife Package'])
        ->and($packages->every(fn (RatePlan $plan): bool => $plan->inclusions()->exists()))->toBeTrue()
        ->and(Reservation::query()->where('property_id', $property->id)->count())->toBe(16)
        ->and(ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::CheckedIn)->count())->toBe(3)
        ->and($tour->units()->count())->toBe(3)
        ->and($invoice->company->name)->toBe('Borneo Nature Tours')
        ->and(app(ArInvoiceBalance::class)->forInvoice($invoice))->toBe(bcsub((string) $invoice->amount, '200.00', 2));
});

test('the seeder sets up the booking site with About, location and amenities, but does not publish it', function () {
    $this->seed(RimbaLodgeSeeder::class);

    $property = Property::query()->where('name', 'Rimba Kinabatangan Lodge')->sole();
    $site = $property->bookingSite()->sole();

    expect(Feature::for($property)->active(BookingEngine::class))->toBeTrue()
        ->and($site->mode)->toBe(BookingEngineMode::Microsite)
        ->and($site->isPublished())->toBeFalse()
        ->and($property->about)->toContain('Kinabatangan River')
        ->and($property->latitude)->not->toBeNull()
        ->and(RatePlan::query()->where('property_id', $property->id)->bookableOnline()->exists())->toBeTrue()
        ->and($property->amenities()->count())->toBe(36);
});

test('the seeder leaves an existing lodge unchanged', function () {
    $this->seed(RimbaLodgeSeeder::class);
    $this->seed(RimbaLodgeSeeder::class);

    $this->assertDatabaseCount('properties', 1);
    $this->assertDatabaseCount('users', 4);
});

test('the seeder refuses an invalid password without leaving property data', function (?string $password) {
    config(['seeding.boreno_password' => $password]);

    expect(fn () => $this->seed(RimbaLodgeSeeder::class))->toThrow(RuntimeException::class, 'BORENO_SEED_PASSWORD');

    $this->assertDatabaseCount('properties', 0);
    $this->assertDatabaseCount('users', 0);
})->with([null, '', 'short']);
