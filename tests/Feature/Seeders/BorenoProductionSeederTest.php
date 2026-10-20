<?php

use App\Enums\PropertyStatus;
use App\Models\ArInvoice;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\BorenoProductionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['seeding.boreno_password' => 'private-boreno-2026', 'estatell.demo.password' => null]);
});

test('the production seeder builds Boreno with full sample data and separate staff logins', function () {
    $this->seed(BorenoProductionSeeder::class);

    $property = Property::query()->where('name', 'Boreno Hotel')->sole();
    $owner = User::query()->where('email', 'owner@boreno.com')->sole();

    expect($property->status)->toBe(PropertyStatus::Live);
    expect($owner->property_id)->toBe($property->id);
    expect(Hash::check('private-boreno-2026', $owner->password))->toBeTrue();
    expect(User::query()->where('property_id', $property->id)->orderBy('email')->pluck('email')->all())
        ->toBe(['frontdesk@boreno.com', 'housekeeping@boreno.com', 'manager@boreno.com', 'owner@boreno.com']);
    expect(Room::query()->where('property_id', $property->id)->count())->toBe(30);
    expect(Reservation::query()->where('property_id', $property->id)->count())->toBe(18);
    expect(ArInvoice::query()->where('property_id', $property->id)->count())->toBe(4);
});

test('the production seeder leaves an existing Boreno property and its password unchanged', function () {
    $this->seed(BorenoProductionSeeder::class);
    config(['seeding.boreno_password' => 'different-password']);

    $this->seed(BorenoProductionSeeder::class);

    $this->assertDatabaseCount('properties', 1);
    $this->assertDatabaseCount('users', 4);
    $this->assertDatabaseCount('reservations', 18);
    expect(Hash::check('private-boreno-2026', User::query()->where('email', 'owner@boreno.com')->sole()->password))->toBeTrue();
});

test('the production seeder refuses an invalid password without leaving property data', function (?string $password) {
    config(['seeding.boreno_password' => $password]);

    expect(fn () => $this->seed(BorenoProductionSeeder::class))->toThrow(RuntimeException::class, 'BORENO_SEED_PASSWORD');

    $this->assertDatabaseCount('properties', 0);
    $this->assertDatabaseCount('users', 0);
})->with([null, '', 'short']);

test('the production seeder does not replace an existing owner email account', function () {
    $existing = User::factory()->create(['email' => 'owner@boreno.com'])->refresh();
    $attributes = $existing->getAttributes();

    expect(fn () => $this->seed(BorenoProductionSeeder::class))->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('properties', 1);
    $this->assertDatabaseMissing('properties', ['name' => 'Boreno Hotel']);
    $this->assertDatabaseCount('users', 1);
    expect($existing->refresh()->getAttributes())->toBe($attributes);
});
