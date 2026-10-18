<?php

use App\Actions\Reservations\EnsureOtaBookingSource;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->property = Property::factory()->create();
    $this->booking = OtaChannel::factory()->create(['code' => 'booking_com', 'name' => 'Booking.com']);
    $this->agoda = OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda']);
    $this->other = OtaChannel::factory()->other()->create();
    $this->ensure = app(EnsureOtaBookingSource::class);
});

test('a real channel gets one OTA row named after the channel, badged from its code', function () {
    $source = ($this->ensure)($this->property, $this->booking);

    expect($source)
        ->name->toBe('Booking.com')
        ->type->toBe(BookingSourceType::Ota)
        ->ota_channel_id->toBe($this->booking->id)
        ->is_system->toBeFalse()
        ->is_active->toBeTrue()
        ->badge_icon->toBeNull()
        ->badge_letters->toBe('B')
        ->badge_color->toBe(SwatchColor::Blue)
        ->property_id->toBe($this->property->id);
});

test('a new OTA row sorts after the staff rows', function () {
    BookingSource::factory()->for($this->property)->create(['sort_order' => 1]);
    BookingSource::factory()->for($this->property)->create(['sort_order' => 3]);

    expect(($this->ensure)($this->property, $this->booking)->sort_order)->toBe(4);
});

test('a real channel is found again, even when staff renamed its row', function () {
    $first = ($this->ensure)($this->property, $this->booking);
    $first->update(['name' => 'BDC']);

    expect(($this->ensure)($this->property, $this->booking)->id)->toBe($first->id)
        ->and(BookingSource::query()->where('property_id', $this->property->id)->count())->toBe(1);
});

test('the Other channel gets a row named by the typed name, badged grey', function () {
    $source = ($this->ensure)($this->property, $this->other, 'Traveloka');

    expect($source)
        ->name->toBe('Traveloka')
        ->ota_channel_id->toBe($this->other->id)
        ->badge_letters->toBe('T')
        ->badge_color->toBe(SwatchColor::Gray);
});

test('Other rows are found by name ignoring case, and keep the first spelling', function () {
    $first = ($this->ensure)($this->property, $this->other, 'Traveloka');
    $again = ($this->ensure)($this->property, $this->other, 'traveloka');

    expect($again->id)->toBe($first->id)
        ->and($again->name)->toBe('Traveloka')
        ->and(BookingSource::query()->where('property_id', $this->property->id)->count())->toBe(1);
});

test('a blank Other name falls back to OTA', function () {
    expect(($this->ensure)($this->property, $this->other, '   ')->name)->toBe('OTA');
});

test('a name taken by a direct source gets " (OTA)"', function () {
    BookingSource::factory()->for($this->property)->create(['name' => 'Agoda']);

    $source = ($this->ensure)($this->property, $this->agoda);

    expect($source)
        ->name->toBe('Agoda (OTA)')
        ->type->toBe(BookingSourceType::Ota)
        ->ota_channel_id->toBe($this->agoda->id)
        ->and(($this->ensure)($this->property, $this->agoda)->id)->toBe($source->id);
});

test('a taken " (OTA)" name gets the next number', function () {
    BookingSource::factory()->for($this->property)->create(['name' => 'Agoda']);
    BookingSource::factory()->for($this->property)->create(['name' => 'Agoda (OTA)']);

    expect(($this->ensure)($this->property, $this->agoda)->name)->toBe('Agoda (OTA 2)');
});

test('an inactive row is returned as it is', function () {
    $inactive = BookingSource::factory()->ota($this->booking)->inactive()->create(['property_id' => $this->property->id]);

    $source = ($this->ensure)($this->property, $this->booking);

    expect($source->id)->toBe($inactive->id)
        ->and($source->refresh()->is_active)->toBeFalse();
});

test('another property\'s rows are not reused or changed', function () {
    $foreign = Property::factory()->create();
    $otherRow = ($this->ensure)($foreign, $this->booking);

    $source = ($this->ensure)($this->property, $this->booking);

    expect($source->id)->not->toBe($otherRow->id)
        ->and($source->name)->toBe('Booking.com')
        ->and($otherRow->refresh()->property_id)->toBe($foreign->id);
});

test('only a new row is logged', function () {
    ($this->ensure)($this->property, $this->booking);
    ($this->ensure)($this->property, $this->booking);

    expect(Activity::query()->where('event', 'booking_source.created')->count())->toBe(1);
});
