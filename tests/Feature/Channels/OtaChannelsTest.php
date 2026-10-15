<?php

use App\Models\OtaChannel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function otaChannelsFixture(): OtaChannel
{
    OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda', 'sort_order' => 1]);
    OtaChannel::factory()->create(['code' => 'airbnb', 'name' => 'Airbnb', 'sort_order' => 2]);

    return OtaChannel::factory()->other()->create(['sort_order' => 3]);
}

test('admins see every channel in list order', function () {
    otaChannelsFixture();
    OtaChannel::factory()->inactive()->create(['code' => 'expedia', 'name' => 'Expedia', 'sort_order' => 4]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.channels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/channels/index')
            ->has('channels', 4)
            ->where('channels.0.code', 'agoda')
            ->where('channels.2.isOther', true)
            ->where('channels.3.isActive', false)
        );
});

test('adding a channel takes a code from its name and puts it at the end', function () {
    otaChannelsFixture();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('admin.channels.store'), ['name' => '  Trip.com  '])
        ->assertRedirect(route('admin.channels.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Trip.com added.']);

    $channel = OtaChannel::query()->where('code', 'trip_com')->firstOrFail();

    expect($channel->name)->toBe('Trip.com')
        ->and($channel->sort_order)->toBe(4)
        ->and($channel->is_active)->toBeTrue()
        ->and($channel->is_other)->toBeFalse();
    expect(Activity::query()->where('event', 'ota_channel.created')->count())->toBe(1);
});

test('adding a name that is already on the list is refused', function () {
    otaChannelsFixture();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.channels.store'), ['name' => 'Agoda'])
        ->assertSessionHasErrors(['name' => 'A channel with this name already exists.']);

    expect(OtaChannel::query()->count())->toBe(3);
});

test('a name with a different spelling but the same code is refused with a toast', function () {
    otaChannelsFixture();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.channels.store'), ['name' => 'AGODA!'])
        ->assertRedirect(route('admin.channels.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A channel with this name already exists.']);

    expect(OtaChannel::query()->count())->toBe(3);
});

test('a name without a letter or number is refused', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.channels.store'), ['name' => '!!!'])
        ->assertSessionHasErrors(['name' => 'Use at least one letter or number.']);

    expect(OtaChannel::query()->count())->toBe(0);
});

test('renaming a channel keeps its code', function () {
    otaChannelsFixture();
    $agoda = OtaChannel::query()->where('code', 'agoda')->firstOrFail();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.channels.update', $agoda), ['name' => 'Agoda Partners'])
        ->assertRedirect(route('admin.channels.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Channel renamed.']);

    expect($agoda->refresh())
        ->name->toBe('Agoda Partners')
        ->code->toBe('agoda');
    expect(Activity::query()->where('event', 'ota_channel.renamed')->count())->toBe(1);
});

test('keeping the same name when renaming is not an error', function () {
    otaChannelsFixture();
    $agoda = OtaChannel::query()->where('code', 'agoda')->firstOrFail();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.channels.update', $agoda), ['name' => 'Agoda'])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'ota_channel.renamed')->count())->toBe(0);
});

test('the Other row cannot be renamed', function () {
    $other = otaChannelsFixture();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.channels.update', $other), ['name' => 'Something else'])
        ->assertRedirect(route('admin.channels.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This channel cannot be renamed.']);

    expect($other->refresh())
        ->name->toBe('Other')
        ->is_other->toBeTrue();
    expect(Activity::query()->count())->toBe(0);
});

test('the Other row cannot be turned off', function () {
    $other = otaChannelsFixture();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.channels.active.update', $other), ['active' => false])
        ->assertRedirect(route('admin.channels.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'That change did not apply. Reload the page and try again.']);

    expect($other->refresh()->is_active)->toBeTrue();
    expect(Activity::query()->count())->toBe(0);
});

test('a channel can be turned off and back on, and a repeat click changes nothing', function () {
    otaChannelsFixture();
    $airbnb = OtaChannel::query()->where('code', 'airbnb')->firstOrFail();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(route('admin.channels.active.update', $airbnb), ['active' => false])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Channel saved.']);

    expect($airbnb->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->put(route('admin.channels.active.update', $airbnb), ['active' => false])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'That change did not apply. Reload the page and try again.']);

    $this->actingAs($admin)
        ->put(route('admin.channels.active.update', $airbnb), ['active' => true])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Channel saved.']);

    expect($airbnb->refresh()->is_active)->toBeTrue();
    expect(Activity::query()->whereIn('event', ['ota_channel.deactivated', 'ota_channel.activated'])->count())->toBe(2);
});

test('reordering saves the full list in the new order', function () {
    otaChannelsFixture();
    $ids = OtaChannel::query()->orderBy('sort_order')->pluck('id')->all();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.channels.reorder'), ['order' => [$ids[2], $ids[0], $ids[1]]])
        ->assertRedirect(route('admin.channels.index'));

    expect(OtaChannel::query()->ordered()->pluck('id')->all())->toBe([$ids[2], $ids[0], $ids[1]]);
});

test('reordering with a missing or unknown id is refused and changes nothing', function () {
    otaChannelsFixture();
    $ids = OtaChannel::query()->orderBy('sort_order')->pluck('id')->all();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(route('admin.channels.reorder'), ['order' => [$ids[0], $ids[1]]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The channels changed. Reload the page and try again.']);

    expect(OtaChannel::query()->ordered()->pluck('id')->all())->toBe($ids);
    expect(Activity::query()->count())->toBe(0);
});

test('channels are never deleted', function () {
    $other = otaChannelsFixture();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->delete(route('admin.channels.update', $other))
        ->assertStatus(405);

    expect(OtaChannel::query()->count())->toBe(3);
});

test('staff never see the channels page or change a channel', function () {
    $staff = User::factory()->create();
    $channel = otaChannelsFixture();

    $this->actingAs($staff)->get(route('admin.channels.index'))->assertNotFound();
    $this->actingAs($staff)->post(route('admin.channels.store'), ['name' => 'Trip.com'])->assertNotFound();
    $this->actingAs($staff)->put(route('admin.channels.update', $channel), ['name' => 'Nope'])->assertNotFound();
    $this->actingAs($staff)->put(route('admin.channels.active.update', $channel), ['active' => false])->assertNotFound();
    $this->actingAs($staff)->put(route('admin.channels.reorder'), ['order' => []])->assertNotFound();

    expect(OtaChannel::query()->where('name', 'Nope')->exists())->toBeFalse()
        ->and($channel->refresh()->is_active)->toBeTrue();
});

test('guests must sign in before seeing the channels page', function () {
    $this->get(route('admin.channels.index'))->assertRedirect(route('login'));
    $this->post(route('admin.channels.store'), ['name' => 'Trip.com'])->assertRedirect(route('login'));
});

test('admin writes need confirmed two-factor when the switch is on', function (string $write) {
    config(['estatell.support_require_two_factor' => true]);
    otaChannelsFixture();
    $agoda = OtaChannel::query()->where('code', 'agoda')->firstOrFail();
    $idsBefore = OtaChannel::query()->ordered()->pluck('id')->all();
    $admin = User::factory()->admin()->create();

    $response = match ($write) {
        'add' => $this->actingAs($admin)->post(route('admin.channels.store'), ['name' => 'Trip.com']),
        'rename' => $this->actingAs($admin)->put(route('admin.channels.update', $agoda), ['name' => 'Agoda Partners']),
        'deactivate' => $this->actingAs($admin)->put(route('admin.channels.active.update', $agoda), ['active' => false]),
        'reorder' => $this->actingAs($admin)->put(route('admin.channels.reorder'), ['order' => array_reverse($idsBefore)]),
    };

    $response->assertRedirect();

    expect(OtaChannel::query()->count())->toBe(3)
        ->and($agoda->refresh())->name->toBe('Agoda')->is_active->toBeTrue()
        ->and(OtaChannel::query()->ordered()->pluck('id')->all())->toBe($idsBefore);
    expect(Activity::query()->count())->toBe(0);
})->with(['add', 'rename', 'deactivate', 'reorder']);

test('admin writes go through when the switch is on and two-factor is confirmed', function () {
    config(['estatell.support_require_two_factor' => true]);
    otaChannelsFixture();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.channels.store'), ['name' => 'Trip.com'])
        ->assertRedirect(route('admin.channels.index'));

    expect(OtaChannel::query()->where('code', 'trip_com')->exists())->toBeTrue();
});
