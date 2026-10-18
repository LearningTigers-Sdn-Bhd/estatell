<?php

use App\Enums\BookingSourceType;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the factory builds valid sources of every type', function () {
    $property = Property::factory()->create();

    $direct = BookingSource::factory()->create(['property_id' => $property->id]);
    $ota = BookingSource::factory()->ota()->create(['property_id' => $property->id]);
    $company = BookingSource::factory()->company()->create(['property_id' => $property->id]);
    $walkIn = BookingSource::factory()->walkIn()->create(['property_id' => $property->id]);

    expect($direct->type)->toBe(BookingSourceType::Direct)
        ->and($ota->type)->toBe(BookingSourceType::Ota)
        ->and($ota->otaChannel->code)->toBe('booking_com')
        ->and($ota->badge_letters)->toBe('B')
        ->and($ota->badge_icon)->toBeNull()
        ->and($company->is_system)->toBeTrue()
        ->and($walkIn->is_system)->toBeTrue()
        ->and($property->bookingSources()->active()->count())->toBe(4);
});

test('the database rejects invalid booking sources', function (Closure $attempt) {
    expect(fn () => DB::transaction($attempt))->toThrow(QueryException::class);
})->with([
    'an OTA source without a channel' => [fn () => BookingSource::factory()->create(['type' => BookingSourceType::Ota])],
    'a Direct source with a channel' => [fn () => BookingSource::factory()->create(['ota_channel_id' => OtaChannel::factory()->create()->id])],
    'a Company source that is not system' => [fn () => BookingSource::factory()->company()->create(['is_system' => false])],
    'a Walk-in source that is not system' => [fn () => BookingSource::factory()->walkIn()->create(['is_system' => false])],
    'a badge with an icon and letters' => [fn () => BookingSource::factory()->create(['badge_letters' => 'AB'])],
    'a badge with neither' => [fn () => BookingSource::factory()->create(['badge_icon' => null])],
    'lower case letters' => [fn () => BookingSource::factory()->create(['badge_icon' => null, 'badge_letters' => 'ab'])],
    'three letters' => [fn () => BookingSource::factory()->create(['badge_icon' => null, 'badge_letters' => 'ABC'])],
    'a blank name' => [fn () => BookingSource::factory()->create(['name' => '  '])],
    'a second system source of one type' => [function () {
        $property = Property::factory()->create();
        BookingSource::factory()->walkIn()->create(['property_id' => $property->id]);
        BookingSource::factory()->walkIn()->create(['property_id' => $property->id, 'name' => 'Walk-in 2']);
    }],
    'a name that differs only in case' => [function () {
        $property = Property::factory()->create();
        BookingSource::factory()->create(['property_id' => $property->id, 'name' => 'Phone']);
        BookingSource::factory()->create(['property_id' => $property->id, 'name' => 'PHONE']);
    }],
    'a bad colour' => [fn () => DB::table('booking_sources')->insert([
        'property_id' => Property::factory()->create()->id, 'name' => 'X', 'type' => 'direct', 'sort_order' => 1,
        'badge_icon' => 'globe', 'badge_color' => 'brown', 'created_at' => now(), 'updated_at' => now(),
    ])],
]);

test('the same name can be used in two properties', function () {
    BookingSource::factory()->create(['name' => 'Phone']);
    BookingSource::factory()->create(['name' => 'Phone']);

    expect(BookingSource::query()->where('name', 'Phone')->count())->toBe(2);
});

test('a source used by a reservation cannot be deleted', function () {
    $reservation = Reservation::factory()->create();

    expect(fn () => DB::transaction(fn () => $reservation->bookingSource->delete()))->toThrow(QueryException::class);
});
