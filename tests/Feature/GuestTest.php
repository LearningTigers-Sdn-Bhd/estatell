<?php

use App\Actions\Guests\UpdateGuest;
use App\Enums\DefaultRoleEnum;
use App\Models\Guest;
use App\Models\GuestNote;
use App\Models\Property;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function guestPayload(array $overrides = []): array
{
    return [
        'full_name' => 'Aisyah Rahman',
        'email' => 'aisyah@example.com',
        'phone' => '012-345 6789',
        'nationality_code' => 'MY',
        'country_code' => 'MY',
        'tags' => [],
        ...$overrides,
    ];
}

test('a guest is added with the phone number saved in E.164', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('guests.store', $property->code), guestPayload(['tags' => ['Corporate']]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Aisyah Rahman added.']);

    $guest = Guest::query()->sole();

    expect($guest->property_id)->toBe($property->id)
        ->and($guest->phone)->toBe('+60123456789')
        ->and($guest->tags->pluck('name')->all())->toBe(['Corporate'])
        ->and(Activity::query()->where('event', 'guest.created')->count())->toBe(1);
});

test('a foreign phone number with a country code is accepted and a bad one is rejected', function () {
    $owner = User::factory()->owner()->create();
    $url = route('guests.store', $owner->property->code);

    $this->actingAs($owner)->post($url, guestPayload(['phone' => '+65 6123 4567']))->assertSessionHasNoErrors();
    expect(Guest::query()->sole()->phone)->toBe('+6561234567');

    $this->post($url, guestPayload(['phone' => '12345']))->assertSessionHasErrors('phone');
    $this->post($url, guestPayload(['full_name' => '']))->assertSessionHasErrors('full_name');
    $this->post($url, guestPayload(['nationality_code' => 'ZZ']))->assertSessionHasErrors('nationality_code');
    expect(Guest::query()->count())->toBe(1);
});

test('updating a guest saves the profile, replaces the tags and logs which fields changed', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create(['full_name' => 'Old Name']);
    $guest->tags()->attach(Tag::factory()->for($property)->create(['name' => 'Old tag']));

    $this->actingAs($owner)
        ->put(route('guests.update', [$property->code, $guest]), guestPayload(['full_name' => 'New Name', 'tags' => ['Regular']]))
        ->assertRedirect(route('guests.show', [$property->code, $guest]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'New Name saved.']);

    expect($guest->refresh()->full_name)->toBe('New Name')
        ->and($guest->tags()->pluck('name')->all())->toBe(['Regular'])
        ->and(Activity::query()->where('event', 'guest.updated')->sole()->properties['changed'])->toContain('full_name');
});

test('a merged or anonymized guest cannot be edited', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create(['merged_into_id' => $survivor->id]);
    $anonymized = Guest::factory()->for($property)->create(['anonymized_at' => now()]);
    $name = $anonymized->full_name;

    $this->actingAs($owner)
        ->put(route('guests.update', [$property->code, $anonymized]), guestPayload())
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => "{$name} cannot be edited."]);
    $this->put(route('guests.update', [$property->code, $merged]), guestPayload())->assertNotFound();

    expect(app(UpdateGuest::class)($owner, $merged, ['full_name' => 'New name']))->toBeFalse()
        ->and($anonymized->refresh()->full_name)->toBe($name)
        ->and($merged->refresh()->full_name)->not->toBe('New name')
        ->and(Activity::query()->where('event', 'guest.updated')->count())->toBe(0);
});

test('tags are reused by name ignoring case and a property never sees another property\'s tags', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $other = Tag::factory()->create(['name' => 'Corporate']);
    $existing = Tag::factory()->for($property)->create(['name' => 'Corporate']);

    $this->actingAs($owner)->post(route('guests.store', $property->code), guestPayload(['tags' => ['corporate', ' CORPORATE ', 'Family']]));

    $guest = Guest::query()->sole();

    expect($guest->tags->pluck('id')->all())->toContain($existing->id)->not->toContain($other->id)
        ->and($guest->tags)->toHaveCount(2)
        ->and(Tag::query()->where('property_id', $property->id)->count())->toBe(2);
});

test('the database rejects a duplicate tag name in one property, ignoring case', function () {
    $property = Property::factory()->create();
    Tag::factory()->for($property)->create(['name' => 'VIP friend']);

    Tag::factory()->create(['name' => 'vip friend']);

    expect(fn () => Tag::factory()->for($property)->create(['name' => 'vip FRIEND']))->toThrow(QueryException::class);
});

test('a guest is marked as VIP and unmarked, with a toast and a log each time', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create(['full_name' => 'Aisyah']);

    $this->actingAs($owner)->from(route('guests.notes.index', [$property->code, $guest]))->post(route('guests.vip.store', [$property->code, $guest]))
        ->assertRedirect(route('guests.notes.index', [$property->code, $guest]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Aisyah is now a VIP.']);
    expect($guest->refresh()->is_vip)->toBeTrue();

    $this->post(route('guests.vip.store', [$property->code, $guest]))->assertInertiaFlash('toast.type', 'error');
    expect(Activity::query()->where('event', 'guest.vip_set')->count())->toBe(1);

    $this->delete(route('guests.vip.destroy', [$property->code, $guest]))->assertInertiaFlash('toast.type', 'success');
    $this->delete(route('guests.vip.destroy', [$property->code, $guest]))->assertInertiaFlash('toast.type', 'error');
    expect($guest->refresh()->is_vip)->toBeFalse()
        ->and(Activity::query()->where('event', 'guest.vip_removed')->count())->toBe(1);
});

test('blacklisting needs a reason and removing from the blacklist clears the date and the reason', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create();
    $url = route('guests.blacklist.store', [$property->code, $guest]);

    $this->actingAs($owner)->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
    expect($guest->refresh()->blacklisted_at)->toBeNull();

    $this->post($url, ['reason' => 'Left without paying'])->assertInertiaFlash('toast.type', 'success');
    expect($guest->refresh()->blacklisted_at)->not->toBeNull()
        ->and($guest->blacklist_reason)->toBe('Left without paying')
        ->and(Activity::query()->where('event', 'guest.blacklisted')->sole()->properties['reason'])->toBe('Left without paying');

    $this->post($url, ['reason' => 'Again'])->assertInertiaFlash('toast.type', 'error');
    expect(Activity::query()->where('event', 'guest.blacklisted')->count())->toBe(1);

    $this->delete(route('guests.blacklist.destroy', [$property->code, $guest]))->assertInertiaFlash('toast.type', 'success');
    expect($guest->refresh()->blacklisted_at)->toBeNull()->and($guest->blacklist_reason)->toBeNull()
        ->and(Activity::query()->where('event', 'guest.unblacklisted')->count())->toBe(1);

    $this->delete(route('guests.blacklist.destroy', [$property->code, $guest]))->assertInertiaFlash('toast.type', 'error');
    expect(Activity::query()->where('event', 'guest.unblacklisted')->count())->toBe(1);
});

test('the database requires a reason for a blacklisted guest', function () {
    expect(fn () => Guest::factory()->create(['blacklisted_at' => now(), 'blacklist_reason' => null]))->toThrow(QueryException::class);
});

test('a note records its author and notes are listed newest first', function () {
    $owner = User::factory()->owner()->create(['name' => 'Siti Owner']);
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create();
    GuestNote::factory()->for($guest)->create(['body' => 'Older note', 'created_at' => now()->subDay()]);

    $this->actingAs($owner)
        ->post(route('guests.notes.store', [$property->code, $guest]), ['body' => 'Likes a quiet room'])
        ->assertRedirect(route('guests.notes.index', [$property->code, $guest]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Note added.']);
    $this->post(route('guests.notes.store', [$property->code, $guest]), ['body' => ''])->assertSessionHasErrors('body');

    expect($guest->notes()->count())->toBe(2)
        ->and(GuestNote::query()->where('body', 'Likes a quiet room')->sole()->author_id)->toBe($owner->id);

    $this->get(route('guests.notes.index', [$property->code, $guest]))
        ->assertInertia(fn (Assert $page) => $page->component('guests/show')
            ->where('tab', 'notes')
            ->where('notes.0.body', 'Likes a quiet room')
            ->where('notes.0.authorName', 'Siti Owner')
            ->where('notes.1.body', 'Older note'));
});

test('the list searches, filters, sorts and paginates the property\'s guests only', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $vipTag = Tag::factory()->for($property)->create(['name' => 'Corporate']);
    $ali = Guest::factory()->for($property)->vip()->create(['full_name' => 'Ali Hassan', 'email' => 'ali@example.com', 'phone' => '+60123456789']);
    $ali->tags()->attach($vipTag);
    Guest::factory()->for($property)->blacklisted()->create(['full_name' => 'Budi Santoso']);
    $survivor = Guest::factory()->for($property)->create(['full_name' => 'Chong Wei']);
    Guest::factory()->for($property)->create(['full_name' => 'Merged Guest', 'merged_into_id' => $survivor->id]);
    Guest::factory()->create(['full_name' => 'Ali Other Property']);
    $url = route('guests.index', $property->code);

    $this->actingAs($owner)->get($url)
        ->assertInertia(fn (Assert $page) => $page->component('guests/index')
            ->has('guests.data', 3)
            ->where('guests.data.0.fullName', 'Ali Hassan')
            ->where('guests.data.0.tags', ['Corporate'])
            ->where('guests.data.1.isBlacklisted', true));

    $this->get("{$url}?filter[search]=ali@exa")->assertInertia(fn (Assert $page) => $page->has('guests.data', 1)->where('guests.data.0.fullName', 'Ali Hassan'));
    $this->get("{$url}?filter[search]=012-345")->assertInertia(fn (Assert $page) => $page->has('guests.data', 1));
    $this->get("{$url}?filter[status]=vip")->assertInertia(fn (Assert $page) => $page->has('guests.data', 1)->where('guests.data.0.fullName', 'Ali Hassan'));
    $this->get("{$url}?filter[status]=blacklisted")->assertInertia(fn (Assert $page) => $page->has('guests.data', 1)->where('guests.data.0.fullName', 'Budi Santoso'));
    $this->get("{$url}?filter[tag]={$vipTag->id}")->assertInertia(fn (Assert $page) => $page->has('guests.data', 1));
    $this->get("{$url}?sort=-full_name")->assertInertia(fn (Assert $page) => $page->where('guests.data.0.fullName', 'Chong Wei'));

    Guest::factory()->for($property)->count(25)->create();
    $this->get($url)->assertInertia(fn (Assert $page) => $page->has('guests.data', 25)->where('guests.meta.total', 28));
});

test('each guest permission is enforced and the default roles hold the right ones', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();

    $guest = Guest::factory()->for($frontDesk->property)->create();
    $code = $frontDesk->property->code;

    $this->actingAs($frontDesk);
    $this->get(route('guests.index', $code))->assertOk();
    $this->get(route('guests.create', $code))->assertOk();
    $this->post(route('guests.vip.store', [$code, $guest]))->assertRedirect();
    $this->post(route('guests.blacklist.store', [$code, $guest]), ['reason' => 'x'])->assertForbidden();
    $this->delete(route('guests.blacklist.destroy', [$code, $guest]))->assertForbidden();

    $financeGuest = Guest::factory()->for($finance->property)->create();
    $this->actingAs($finance);
    $this->get(route('guests.show', [$finance->property->code, $financeGuest]))->assertOk();
    $this->get(route('guests.create', $finance->property->code))->assertForbidden();
    $this->post(route('guests.notes.store', [$finance->property->code, $financeGuest]), ['body' => 'x'])->assertForbidden();

    $this->actingAs($housekeeping)->get(route('guests.index', $housekeeping->property->code))->assertForbidden();

    $managerGuest = Guest::factory()->for($manager->property)->create();
    $this->actingAs($manager)->post(route('guests.blacklist.store', [$manager->property->code, $managerGuest]), ['reason' => 'x'])->assertRedirect();
});

test('another property\'s guest returns 404 on every guest route', function () {
    $owner = User::factory()->owner()->create();
    $code = $owner->property->code;
    $foreign = Guest::factory()->create();

    $this->actingAs($owner);
    $this->get(route('guests.show', [$code, $foreign]))->assertNotFound();
    $this->get(route('guests.edit', [$code, $foreign]))->assertNotFound();
    $this->put(route('guests.update', [$code, $foreign]), guestPayload())->assertNotFound();
    $this->post(route('guests.vip.store', [$code, $foreign]))->assertNotFound();
    $this->post(route('guests.blacklist.store', [$code, $foreign]), ['reason' => 'x'])->assertNotFound();
    $this->post(route('guests.notes.store', [$code, $foreign]), ['body' => 'x'])->assertNotFound();
});

test('the Guests sidebar item shows only with guests.view', function () {
    $owner = User::factory()->owner()->create();
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();

    $this->actingAs($owner)->get(route('dashboard', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('navigation.contexts.0.groups', fn ($groups) => collect($groups)->flatMap(fn ($group) => $group['items'])->pluck('title')->contains('Guests')));

    $this->actingAs($housekeeping)->get(route('dashboard', $housekeeping->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('navigation.contexts.0.groups', fn ($groups) => ! collect($groups)->flatMap(fn ($group) => $group['items'])->pluck('title')->contains('Guests')));
});
