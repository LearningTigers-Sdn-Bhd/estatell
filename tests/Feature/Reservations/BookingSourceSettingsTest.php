<?php

use App\Enums\DefaultRoleEnum;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = $this->owner->property;
    $this->booking = OtaChannel::factory()->create(['code' => 'booking_com', 'name' => 'Booking.com']);
    $this->other = OtaChannel::factory()->other()->create();
    $this->url = fn (string $name, mixed ...$parameters): string => route("settings.booking-sources.{$name}", [$this->property->code, ...$parameters]);
    $this->payload = fn (array $overrides = []): array => [
        'name' => 'WhatsApp',
        'type' => 'direct',
        'badge_icon' => 'message-circle',
        'badge_letters' => null,
        'badge_color' => 'green',
        ...$overrides,
    ];
});

test('the page lists staff rows, inactive rows, then system rows with usage and form data', function () {
    $phone = BookingSource::factory()->for($this->property)->create(['name' => 'Phone', 'sort_order' => 1]);
    BookingSource::factory()->for($this->property)->inactive()->create(['name' => 'Email', 'sort_order' => 2]);
    BookingSource::factory()->ota($this->booking)->for($this->property)->create(['sort_order' => 3]);
    BookingSource::factory()->company()->for($this->property)->create();
    BookingSource::factory()->create(['name' => 'Elsewhere']);
    Reservation::factory()->count(2)->for($this->property)->create(['booking_source_id' => $phone->id]);

    $this->actingAs($this->owner)
        ->get(($this->url)('index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/booking-sources/index')
            ->has('sources', 4)
            ->where('sources.0.badge.name', 'Phone')
            ->where('sources.0.usedCount', 2)
            ->where('sources.1.badge.name', 'Email')
            ->where('sources.2.otaChannelName', 'Booking.com')
            ->where('sources.2.badge.letters', 'B')
            ->where('sources.3.isSystem', true)
            ->where('types', [['value' => 'direct', 'label' => 'Direct'], ['value' => 'ota', 'label' => 'OTA']])
            ->where('channels', fn ($channels) => collect($channels)->pluck('name')->contains('Booking.com') && collect($channels)->firstWhere('isOther', true) !== null)
            ->has('icons', 12)
            ->has('colors', 10));
});

test('the page runs the same queries however many sources there are', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Model::preventLazyLoading();

        try {
            $this->actingAs($this->owner)->get(($this->url)('index'))->assertOk();
        } finally {
            Model::preventLazyLoading(false);
        }

        return count(DB::getQueryLog());
    };

    BookingSource::factory()->count(2)->for($this->property)->create();
    BookingSource::factory()->ota($this->booking)->for($this->property)->create();
    $count();
    $few = $count();
    BookingSource::factory()->count(10)->for($this->property)->create();
    Reservation::factory()->count(3)->for($this->property)->create();

    expect($count())->toBe($few);
});

test('owners create a Direct source at the end with a toast', function () {
    BookingSource::factory()->for($this->property)->create(['sort_order' => 3]);

    $this->actingAs($this->owner)
        ->post(($this->url)('store'), ($this->payload)())
        ->assertRedirect(($this->url)('index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'WhatsApp created.']);

    $source = BookingSource::query()->where('name', 'WhatsApp')->sole();

    expect($source->property_id)->toBe($this->property->id)
        ->and($source->sort_order)->toBe(4)
        ->and($source->badge_color->value)->toBe('green')
        ->and(Activity::query()->where('event', 'booking_source.created')->count())->toBe(1);
});

test('creating an OTA source on a real channel and on Other', function () {
    $this->actingAs($this->owner)
        ->post(($this->url)('store'), ($this->payload)(['name' => 'Booking.com', 'type' => 'ota', 'ota_channel_id' => $this->booking->id, 'badge_icon' => null, 'badge_letters' => 'b']))
        ->assertSessionHasNoErrors();

    $this->post(($this->url)('store'), ($this->payload)(['name' => 'ignored', 'type' => 'ota', 'ota_channel_id' => $this->other->id, 'channel_name' => ' Traveloka ', 'badge_icon' => null, 'badge_letters' => 'T']))
        ->assertSessionHasNoErrors();

    $ota = BookingSource::query()->where('name', 'Booking.com')->sole();

    expect($ota->badge_letters)->toBe('B')
        ->and($ota->ota_channel_id)->toBe($this->booking->id)
        ->and(BookingSource::query()->where('name', 'Traveloka')->sole()->ota_channel_id)->toBe($this->other->id);
});

test('create fields are validated', function (array $overrides, string $field, string $message) {
    BookingSource::factory()->for($this->property)->create(['name' => 'Phone']);
    BookingSource::factory()->ota($this->booking)->for($this->property)->create();
    BookingSource::factory()->for($this->property)->create(['name' => 'Traveloka']);
    BookingSource::factory()->create(['name' => 'Elsewhere']);

    foreach (['booking' => $this->booking->id, 'other' => $this->other->id] as $key => $id) {
        foreach ($overrides as $name => $value) {
            if ($value === $key) {
                $overrides[$name] = $id;
            }
        }
    }

    $this->actingAs($this->owner)
        ->post(($this->url)('store'), ($this->payload)($overrides))
        ->assertSessionHasErrors([$field => $message]);

    expect($this->property->bookingSources()->count())->toBe(3);
})->with([
    'name required' => [['name' => ''], 'name', 'Enter a name.'],
    'name unique ignoring case' => [['name' => 'phone'], 'name', 'Another booking source already uses this name.'],
    'type is Direct or OTA' => [['type' => 'company'], 'type', 'The selected type is invalid.'],
    'OTA needs a channel' => [['type' => 'ota'], 'ota_channel_id', 'Choose an OTA channel.'],
    'real channel already has a row' => [['type' => 'ota', 'ota_channel_id' => 'booking'], 'ota_channel_id', 'This property already has a booking source for Booking.com.'],
    'Other needs a channel name' => [['type' => 'ota', 'ota_channel_id' => 'other', 'channel_name' => ''], 'channel_name', 'Enter the channel name.'],
    'Other channel name unique' => [['type' => 'ota', 'ota_channel_id' => 'other', 'channel_name' => 'traveloka'], 'channel_name', 'Another booking source already uses this name.'],
    'badge needs one choice' => [['badge_icon' => null, 'badge_letters' => null], 'badge_icon', 'Choose an icon or type one or two letters.'],
    'badge cannot be both' => [['badge_letters' => 'AB'], 'badge_icon', 'Choose an icon or type one or two letters.'],
    'letters are letters or digits' => [['badge_icon' => null, 'badge_letters' => 'A-'], 'badge_letters', 'Letters must be one or two letters or digits.'],
    'letters at most two' => [['badge_icon' => null, 'badge_letters' => 'ABC'], 'badge_letters', 'Letters must be one or two letters or digits.'],
    'color required' => [['badge_color' => ''], 'badge_color', 'Choose a color.'],
]);

test('update saves the edit and keeps a system row to its badge', function () {
    $source = BookingSource::factory()->for($this->property)->create(['name' => 'Phone']);
    $company = BookingSource::factory()->company()->for($this->property)->create();

    $this->actingAs($this->owner)
        ->put(($this->url)('update', $source), ($this->payload)(['name' => 'Call', 'is_active' => false]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Call saved.']);

    expect($source->refresh()->name)->toBe('Call')->and($source->is_active)->toBeFalse();

    $this->put(($this->url)('update', $company), ($this->payload)(['name' => 'Agents', 'badge_icon' => 'star', 'is_active' => false]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Company / Travel agent saved.']);

    expect($company->refresh()->name)->toBe('Company / Travel agent')
        ->and($company->is_active)->toBeTrue()
        ->and($company->badge_icon->value)->toBe('star');
});

test('a used source keeps its type and channel even when the request sends others', function () {
    $source = BookingSource::factory()->ota($this->booking)->for($this->property)->create();
    Reservation::factory()->for($this->property)->create(['booking_source_id' => $source->id]);

    $this->actingAs($this->owner)
        ->put(($this->url)('update', $source), ($this->payload)(['name' => 'Booking', 'type' => 'direct', 'is_active' => true, 'badge_icon' => null, 'badge_letters' => 'B']))
        ->assertSessionHasNoErrors();

    expect($source->refresh()->name)->toBe('Booking')
        ->and($source->ota_channel_id)->toBe($this->booking->id)
        ->and($source->type->value)->toBe('ota');
});

test('update validates the name and lets a source keep its own', function () {
    BookingSource::factory()->for($this->property)->create(['name' => 'Phone']);
    $source = BookingSource::factory()->for($this->property)->create(['name' => 'Email']);

    $this->actingAs($this->owner)
        ->put(($this->url)('update', $source), ($this->payload)(['name' => 'PHONE', 'is_active' => true]))
        ->assertSessionHasErrors(['name' => 'Another booking source already uses this name.']);

    $this->put(($this->url)('update', $source), ($this->payload)(['name' => 'email', 'is_active' => true]))
        ->assertSessionHasNoErrors();
});

test('reordering saves positions and shows an error toast on a stale list', function () {
    $a = BookingSource::factory()->for($this->property)->create(['sort_order' => 1]);
    $b = BookingSource::factory()->for($this->property)->create(['sort_order' => 2]);

    $this->actingAs($this->owner)
        ->put(($this->url)('reorder'), ['order' => [$b->id, $a->id]])
        ->assertSessionHasNoErrors();

    expect($b->refresh()->sort_order)->toBe(1);

    $this->put(($this->url)('reorder'), ['order' => [$a->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The booking sources changed. Reload the page and try again.']);

    $this->put(($this->url)('reorder'), ['order' => [$a->id, $a->id]])->assertSessionHasErrors('order.0');

    expect($b->refresh()->sort_order)->toBe(1);
});

test('delete removes an unused source and refuses a used or system one with a toast', function () {
    $unused = BookingSource::factory()->for($this->property)->create(['name' => 'Flyer']);
    $used = BookingSource::factory()->for($this->property)->create(['name' => 'Phone']);
    Reservation::factory()->for($this->property)->create(['booking_source_id' => $used->id]);
    $walkIn = BookingSource::factory()->walkIn()->for($this->property)->create();

    $this->actingAs($this->owner)
        ->delete(($this->url)('destroy', $unused))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Flyer deleted.']);

    $this->delete(($this->url)('destroy', $used))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Phone cannot be deleted. Turn it off with Available instead.']);
    $this->delete(($this->url)('destroy', $walkIn))->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Walk-in cannot be deleted. Turn it off with Available instead.']);

    expect(BookingSource::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(BookingSource::query()->whereKey([$used->id, $walkIn->id])->count())->toBe(2);
});

test('routes need settings.manage and stay inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $mine = BookingSource::factory()->for($frontDesk->property)->create();
    $otherProperty = Property::factory()->create();
    $theirs = BookingSource::factory()->for($otherProperty)->create(['name' => 'Theirs']);

    $this->actingAs($frontDesk);
    $code = $frontDesk->property->code;
    $this->get(route('settings.booking-sources.index', $code))->assertForbidden();
    $this->post(route('settings.booking-sources.store', $code), ($this->payload)())->assertForbidden();
    $this->put(route('settings.booking-sources.reorder', $code), ['order' => []])->assertForbidden();
    $this->put(route('settings.booking-sources.update', [$code, $mine]), ($this->payload)(['is_active' => true]))->assertForbidden();
    $this->delete(route('settings.booking-sources.destroy', [$code, $mine]))->assertForbidden();

    $this->actingAs($this->owner)
        ->put(($this->url)('update', $theirs), ($this->payload)(['is_active' => false]))
        ->assertNotFound();
    $this->delete(($this->url)('destroy', $theirs))->assertNotFound();
    $this->get(route('settings.booking-sources.index', $otherProperty->code))->assertNotFound();

    expect($theirs->refresh()->name)->toBe('Theirs')->and($theirs->is_active)->toBeTrue();
});

test('the settings sidebar lists booking sources for settings managers only', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($this->owner)
        ->get(($this->url)('index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.3.groups.0.items', fn ($items) => collect($items)->firstWhere('title', 'Booking sources')['active'] === true));

    $this->actingAs($frontDesk)
        ->get(route('dashboard', $frontDesk->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('navigation.contexts', fn ($contexts) => ! str_contains(json_encode($contexts), 'Booking sources')));
});
