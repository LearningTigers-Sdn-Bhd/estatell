<?php

use App\Enums\BookingSourceType;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\OtaChannelSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Puts the database back in the shape before the migration (the test runs inside a transaction, and Postgres DDL rolls
 * back with it), so the backfill can run on old-shaped rows.
 */
function backfillMigration(): object
{
    return include database_path('migrations/2026_10_18_120000_replace_reservation_source_with_booking_sources.php');
}

/**
 * A reservation row in the old shape. Run it between `down()` and `up()`: the factory cannot build it then.
 */
function oldReservation(Property $property, string $source, ?string $channelName = null): int
{
    return DB::table('reservations')->insertGetId([
        'property_id' => $property->id,
        'reference' => fake()->unique()->bothify('OLD-########'),
        'status' => 'confirmed',
        'booker_guest_id' => Guest::factory()->create(['property_id' => $property->id])->id,
        'billing_mode' => 'master_pays',
        'source' => $source,
        'channel_name' => $channelName,
        'created_by' => User::factory()->create(['property_id' => $property->id])->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function sourceOf(int $reservationId): BookingSource
{
    return BookingSource::query()->findOrFail(DB::table('reservations')->where('id', $reservationId)->value('booking_source_id'));
}

test('the migration maps every old source, creates the Direct row only where needed, and adds the defaults everywhere', function () {
    $this->seed(OtaChannelSeeder::class);
    $migration = backfillMigration();
    $property = Property::factory()->create();
    $empty = Property::factory()->create();

    $migration->down();
    expect(Schema::hasColumn('reservations', 'source'))->toBeTrue()
        ->and(Schema::hasTable('booking_sources'))->toBeFalse();

    $direct = oldReservation($property, 'direct');
    $phone = oldReservation($property, 'phone');
    $walkIn = oldReservation($property, 'walk_in');
    $portal = oldReservation($property, 'portal');
    $secondDirect = oldReservation($property, 'direct');

    $migration->up();

    expect(Schema::hasColumn('reservations', 'source'))->toBeFalse()
        ->and(Schema::hasColumn('reservations', 'channel_name'))->toBeFalse()
        ->and(sourceOf($phone)->name)->toBe('Phone')
        ->and(sourceOf($walkIn)->type)->toBe(BookingSourceType::WalkIn)
        ->and(sourceOf($portal)->type)->toBe(BookingSourceType::Company)
        ->and(sourceOf($direct)->name)->toBe('Direct')
        ->and(sourceOf($secondDirect)->id)->toBe(sourceOf($direct)->id)
        ->and($property->bookingSources()->count())->toBe(6)
        ->and($empty->bookingSources()->pluck('name')->all())->toContain('Phone', 'WhatsApp', 'Email', 'Company / Travel agent', 'Walk-in')
        ->and($empty->bookingSources()->count())->toBe(5);
});

test('an old channel booking goes to the matching OTA source, a real channel, or Other', function () {
    $this->seed(OtaChannelSeeder::class);
    $migration = backfillMigration();
    $property = Property::factory()->create();
    $agoda = OtaChannel::query()->where('code', 'agoda')->sole();
    $other = OtaChannel::query()->where('is_other', true)->sole();
    PropertyOtaCredential::factory()->for($property)->create(['ota_channel_id' => $agoda->id]);
    PropertyOtaCredential::factory()->for($property)->create(['ota_channel_id' => $other->id, 'channel_name' => 'Traveloka']);

    $migration->down();

    $byCredential = oldReservation($property, 'channel', 'agoda');
    $byOther = oldReservation($property, 'channel', 'TRAVELOKA');
    $byChannel = oldReservation($property, 'channel', 'Booking.com');
    $unknown = oldReservation($property, 'channel', 'Trip.com');
    $blank = oldReservation($property, 'channel', null);

    $migration->up();

    expect(sourceOf($byCredential)->name)->toBe('Agoda')
        ->and(sourceOf($byCredential)->otaChannel->code)->toBe('agoda')
        ->and(sourceOf($byCredential)->badge_color->value)->toBe('red')
        ->and(sourceOf($byOther)->name)->toBe('Traveloka')
        ->and(sourceOf($byOther)->ota_channel_id)->toBe($other->id)
        ->and(sourceOf($byChannel)->name)->toBe('Booking.com')
        ->and(sourceOf($byChannel)->otaChannel->code)->toBe('booking_com')
        ->and(sourceOf($byChannel)->badge_letters)->toBe('B')
        ->and(sourceOf($unknown)->name)->toBe('Trip.com')
        ->and(sourceOf($unknown)->ota_channel_id)->toBe($other->id)
        ->and(sourceOf($blank)->name)->toBe('OTA')
        ->and(sourceOf($blank)->ota_channel_id)->toBe($other->id)
        ->and($property->bookingSources()->where('type', BookingSourceType::Ota)->count())->toBe(5);
});

test('down maps back from the source type', function () {
    $this->seed(OtaChannelSeeder::class);
    $migration = backfillMigration();
    $phone = Reservation::factory()->create();
    $walkIn = Reservation::factory()->walkIn()->create();
    $ota = Reservation::factory()->ota('Agoda')->create();
    $company = Reservation::factory()->company()->create();
    DB::table('booking_sources')->where('id', $phone->booking_source_id)->update(['name' => 'Phone']);

    $migration->down();

    $source = fn (Reservation $reservation): array => (array) DB::table('reservations')->where('id', $reservation->id)->first(['source', 'channel_name']);

    expect($source($phone))->toBe(['source' => 'phone', 'channel_name' => null])
        ->and($source($walkIn)['source'])->toBe('walk_in')
        ->and($source($ota))->toBe(['source' => 'channel', 'channel_name' => 'Agoda'])
        ->and($source($company)['source'])->toBe('portal')
        ->and(Schema::hasTable('booking_sources'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('reservations', 'booking_source_id'))->toBeTrue();
});
