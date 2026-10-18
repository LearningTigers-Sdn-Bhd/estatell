<?php

use App\Actions\Reservations\CreateBookingSource;
use App\Actions\Reservations\DeleteBookingSource;
use App\Actions\Reservations\ReorderBookingSources;
use App\Actions\Reservations\UpdateBookingSource;
use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\BookingSourceUsage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = $this->owner->property;
    $this->events = fn (string $event): int => Activity::query()->where('event', $event)->count();
});

test('creating a Direct source puts it at the end of the active list with the Globe badge', function () {
    BookingSource::factory()->for($this->property)->create(['sort_order' => 4]);
    BookingSource::factory()->for($this->property)->inactive()->create(['sort_order' => 9]);

    $source = app(CreateBookingSource::class)($this->property, $this->owner, ['name' => ' WhatsApp ', 'type' => 'direct']);

    expect($source->name)->toBe('WhatsApp')
        ->and($source->type)->toBe(BookingSourceType::Direct)
        ->and($source->sort_order)->toBe(5)
        ->and($source->is_active)->toBeTrue()
        ->and($source->is_system)->toBeFalse()
        ->and($source->badge_icon)->toBe(BookingSourceIcon::Globe)
        ->and($source->badge_color)->toBe(SwatchColor::Gray)
        ->and(($this->events)('booking_source.created'))->toBe(1);
});

test('creating an OTA source defaults to its first letter on the channel color', function () {
    $channel = OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda']);

    $source = app(CreateBookingSource::class)($this->property, $this->owner, ['name' => 'Agoda', 'type' => 'ota', 'ota_channel_id' => $channel->id]);

    expect($source->ota_channel_id)->toBe($channel->id)
        ->and($source->badge_icon)->toBeNull()
        ->and($source->badge_letters)->toBe('A')
        ->and($source->badge_color)->toBe(SwatchColor::Red);
});

test('Other channel can have many sources, a real channel only one', function () {
    $other = OtaChannel::factory()->other()->create();
    $agoda = OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda']);
    $create = app(CreateBookingSource::class);

    expect($create($this->property, $this->owner, ['name' => 'Traveloka', 'type' => 'ota', 'ota_channel_id' => $other->id]))->not->toBeNull()
        ->and($create($this->property, $this->owner, ['name' => 'Trip.com', 'type' => 'ota', 'ota_channel_id' => $other->id]))->not->toBeNull()
        ->and($create($this->property, $this->owner, ['name' => 'Agoda', 'type' => 'ota', 'ota_channel_id' => $agoda->id]))->not->toBeNull()
        ->and($create($this->property, $this->owner, ['name' => 'Agoda 2', 'type' => 'ota', 'ota_channel_id' => $agoda->id]))->toBeNull();
});

test('creating refuses system types, a missing or inactive channel and a taken name without logging', function (array $attributes) {
    OtaChannel::factory()->inactive()->create(['code' => 'off']);
    BookingSource::factory()->for($this->property)->create(['name' => 'Phone']);

    $attributes['ota_channel_id'] = $attributes['ota_channel_id'] ?? null;
    $attributes['ota_channel_id'] = $attributes['ota_channel_id'] === 'inactive' ? OtaChannel::query()->where('code', 'off')->value('id') : $attributes['ota_channel_id'];

    expect(app(CreateBookingSource::class)($this->property, $this->owner, $attributes))->toBeNull()
        ->and(($this->events)('booking_source.created'))->toBe(0)
        ->and($this->property->bookingSources()->count())->toBe(1);
})->with([
    'company type' => [['name' => 'X', 'type' => 'company']],
    'walk-in type' => [['name' => 'X', 'type' => 'walk_in']],
    'no channel' => [['name' => 'X', 'type' => 'ota']],
    'inactive channel' => [['name' => 'X', 'type' => 'ota', 'ota_channel_id' => 'inactive']],
    'taken name ignoring case' => [['name' => 'phone', 'type' => 'direct']],
]);

test('updating changes name and badge, swaps icon for letters and logs once', function () {
    $source = BookingSource::factory()->for($this->property)->create(['name' => 'Phone']);
    $update = app(UpdateBookingSource::class);

    expect($update($this->owner, $source, ['name' => 'Call', 'badge_letters' => 'ca', 'badge_color' => 'teal']))->toBeTrue();

    $source->refresh();

    expect($source->name)->toBe('Call')
        ->and($source->badge_icon)->toBeNull()
        ->and($source->badge_letters)->toBe('CA')
        ->and($source->badge_color)->toBe(SwatchColor::Teal)
        ->and(($this->events)('booking_source.updated'))->toBe(1);

    expect($update($this->owner, $source, ['name' => 'Call', 'badge_letters' => 'CA']))->toBeTrue()
        ->and(($this->events)('booking_source.updated'))->toBe(1);

    $update($this->owner, $source, ['badge_icon' => 'phone']);

    expect($source->refresh()->badge_icon)->toBe(BookingSourceIcon::Phone)->and($source->badge_letters)->toBeNull();
});

test('turning a source back on puts it at the end of the active list', function () {
    $off = BookingSource::factory()->for($this->property)->inactive()->create(['sort_order' => 1]);
    BookingSource::factory()->for($this->property)->create(['sort_order' => 6]);

    app(UpdateBookingSource::class)($this->owner, $off, ['is_active' => true]);

    expect($off->refresh()->is_active)->toBeTrue()->and($off->sort_order)->toBe(7);
});

test('a system row accepts only a badge change', function () {
    $company = BookingSource::factory()->company()->for($this->property)->create();
    $update = app(UpdateBookingSource::class);

    foreach ([['name' => 'Agents'], ['is_active' => false], ['type' => 'direct']] as $attributes) {
        expect($update($this->owner, $company, $attributes))->toBeFalse();
    }

    expect(($this->events)('booking_source.updated'))->toBe(0)
        ->and($update($this->owner, $company, ['name' => 'Company / Travel agent', 'is_active' => true, 'badge_icon' => 'star', 'badge_color' => 'pink']))->toBeTrue()
        ->and($company->refresh()->badge_icon)->toBe(BookingSourceIcon::Star)
        ->and($company->name)->toBe('Company / Travel agent')
        ->and(($this->events)('booking_source.updated'))->toBe(1);
});

test('a used source keeps its type and channel but can be renamed and turned off', function () {
    $agoda = OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda']);
    $source = BookingSource::factory()->ota($agoda)->for($this->property)->create();
    Reservation::factory()->for($this->property)->create(['booking_source_id' => $source->id]);
    $update = app(UpdateBookingSource::class);

    expect($update($this->owner, $source, ['type' => 'direct']))->toBeFalse()
        ->and($update($this->owner, $source, ['ota_channel_id' => OtaChannel::factory()->create()->id]))->toBeFalse()
        ->and($update($this->owner, $source, ['type' => 'ota', 'ota_channel_id' => $agoda->id, 'name' => 'Agoda HK', 'is_active' => false]))->toBeTrue()
        ->and($source->refresh()->name)->toBe('Agoda HK')
        ->and($source->is_active)->toBeFalse();
});

test('an unused source can switch between Direct and OTA', function () {
    $agoda = OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda']);
    BookingSource::factory()->ota($agoda)->for($this->property)->create();
    $source = BookingSource::factory()->for($this->property)->create();
    $update = app(UpdateBookingSource::class);

    expect($update($this->owner, $source, ['type' => 'ota', 'ota_channel_id' => $agoda->id]))->toBeFalse()
        ->and($update($this->owner, $source, ['type' => 'ota']))->toBeFalse();

    $ota = OtaChannel::factory()->create(['code' => 'expedia', 'name' => 'Expedia']);

    expect($update($this->owner, $source, ['type' => 'ota', 'ota_channel_id' => $ota->id]))->toBeTrue()
        ->and($source->refresh()->type)->toBe(BookingSourceType::Ota)
        ->and($update($this->owner, $source, ['type' => 'direct', 'ota_channel_id' => $ota->id]))->toBeTrue()
        ->and($source->refresh()->ota_channel_id)->toBeNull();
});

test('updating refuses a name another source uses', function () {
    BookingSource::factory()->for($this->property)->create(['name' => 'Phone']);
    $source = BookingSource::factory()->for($this->property)->create(['name' => 'Email']);

    expect(app(UpdateBookingSource::class)($this->owner, $source, ['name' => 'PHONE']))->toBeFalse()
        ->and($source->refresh()->name)->toBe('Email');
});

test('reordering sets the positions of exactly the active staff sources', function () {
    $a = BookingSource::factory()->for($this->property)->create(['sort_order' => 1]);
    $b = BookingSource::factory()->for($this->property)->create(['sort_order' => 2]);
    BookingSource::factory()->for($this->property)->inactive()->create(['sort_order' => 3]);
    $company = BookingSource::factory()->company()->for($this->property)->create();
    $reorder = app(ReorderBookingSources::class);

    expect($reorder($this->property, $this->owner, [$b->id, $a->id]))->toBeTrue()
        ->and($b->refresh()->sort_order)->toBe(1)
        ->and($a->refresh()->sort_order)->toBe(2)
        ->and(($this->events)('booking_source.reordered'))->toBe(1);

    foreach ([[$a->id], [$a->id, $b->id, $company->id], [$a->id, $b->id, BookingSource::factory()->create()->id], [$a->id, $a->id]] as $ids) {
        expect($reorder($this->property, $this->owner, $ids))->toBeFalse();
    }

    expect(($this->events)('booking_source.reordered'))->toBe(1)->and($b->refresh()->sort_order)->toBe(1);
});

test('deleting removes an unused source and logs it', function () {
    $source = BookingSource::factory()->for($this->property)->create(['name' => 'Flyer']);

    expect(app(DeleteBookingSource::class)($this->owner, $source))->toBeTrue()
        ->and(BookingSource::query()->whereKey($source->id)->exists())->toBeFalse()
        ->and(($this->events)('booking_source.deleted'))->toBe(1);
});

test('deleting refuses a used or system source without logging', function () {
    $used = BookingSource::factory()->for($this->property)->create();
    Reservation::factory()->for($this->property)->create(['booking_source_id' => $used->id]);
    $walkIn = BookingSource::factory()->walkIn()->for($this->property)->create();
    $delete = app(DeleteBookingSource::class);

    expect($delete($this->owner, $used))->toBeFalse()
        ->and($delete($this->owner, $walkIn))->toBeFalse()
        ->and(BookingSource::query()->count())->toBe(2)
        ->and(($this->events)('booking_source.deleted'))->toBe(0);
});

test('usage counts reservations per source of the property', function () {
    $phone = BookingSource::factory()->for($this->property)->create();
    $unused = BookingSource::factory()->for($this->property)->create();
    Reservation::factory()->count(2)->for($this->property)->create(['booking_source_id' => $phone->id]);
    Reservation::factory()->create();
    $usage = app(BookingSourceUsage::class);

    expect($usage->counts($this->property))->toBe([$phone->id => 2])
        ->and($usage->count($phone))->toBe(2)
        ->and($usage->isUsed($phone))->toBeTrue()
        ->and($usage->isUsed($unused))->toBeFalse();
});
