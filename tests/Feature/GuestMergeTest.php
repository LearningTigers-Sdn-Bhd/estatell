<?php

use App\Actions\Guests\MergeGuests;
use App\Enums\DefaultRoleEnum;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\GuestNote;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function mergeUrl(User $user, Guest $survivor): string
{
    return route('guests.merge.store', [$user->property->code, $survivor]);
}

test('a merge moves notes, tags and documents and the merged guest keeps only its name', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $shared = Tag::factory()->for($property)->create(['name' => 'Corporate']);
    $onlyMerged = Tag::factory()->for($property)->create(['name' => 'Family']);
    $survivor = Guest::factory()->for($property)->create(['full_name' => 'Aisyah Rahman', 'phone' => '+60123456789', 'email' => null]);
    $merged = Guest::factory()->for($property)->create(['full_name' => 'Rahman Aisyah', 'phone' => '+60199999999', 'email' => 'old@example.com', 'city' => 'Penang', 'date_of_birth' => '1990-01-01']);
    $survivor->tags()->attach($shared);
    $merged->tags()->attach([$shared->id, $onlyMerged->id]);
    GuestNote::factory()->for($merged)->count(2)->create();
    $document = GuestIdentityDocument::factory()->for($merged)->create(['number' => '900101141234']);

    $this->actingAs($owner)
        ->post(mergeUrl($owner, $survivor), ['duplicate_id' => $merged->id])
        ->assertRedirect(route('guests.show', [$property->code, $survivor]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rahman Aisyah merged into Aisyah Rahman.']);

    $survivor->refresh();
    $merged->refresh();

    expect($survivor->notes()->count())->toBe(2)
        ->and($merged->notes()->count())->toBe(0)
        ->and($survivor->documents()->pluck('id')->all())->toBe([$document->id])
        ->and($merged->documents()->count())->toBe(0)
        ->and($survivor->tags()->pluck('name')->sort()->values()->all())->toBe(['Corporate', 'Family'])
        ->and($merged->tags()->count())->toBe(0)
        ->and(GuestIdentityDocument::query()->whereBlind('number', 'number_index', '900101141234')->count())->toBe(1)
        ->and($survivor->phone)->toBe('+60123456789')
        ->and($survivor->email)->toBe('old@example.com')
        ->and($survivor->city)->toBe('Penang')
        ->and($survivor->date_of_birth->toDateString())->toBe('1990-01-01')
        ->and($merged->merged_into_id)->toBe($survivor->id)
        ->and($merged->full_name)->toBe('Rahman Aisyah')
        ->and([$merged->email, $merged->phone, $merged->city, $merged->date_of_birth, $merged->nationality_code, $merged->country_code])->each->toBeNull();

    $log = Activity::query()->where('event', 'guest.merged')->sole();

    expect($log->subject_id)->toBe($survivor->id)
        ->and($log->properties['merged_guest_id'])->toBe($merged->id)
        ->and($log->properties['notes_moved'])->toBe(2)
        ->and($log->properties['documents_moved'])->toBe(1)
        ->and($log->properties['tags_added'])->toBe(1)
        ->and($log->properties['fields_filled'])->toBe(['email', 'date_of_birth', 'city'])
        ->and(json_encode($log->properties))->not->toContain('old@example.com')->not->toContain('Penang');
});

test('VIP, blacklist and marketing consent carry over and the survivor\'s own blacklist wins', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $action = app(MergeGuests::class);

    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->vip()->blacklisted('Left without paying')->create(['marketing_consent_at' => '2026-01-01 10:00:00']);
    expect($action($owner, $survivor, $merged))->toBeTrue();
    $survivor->refresh();
    $merged->refresh();

    expect($survivor->is_vip)->toBeTrue()
        ->and($survivor->blacklist_reason)->toBe('Left without paying')
        ->and($survivor->marketing_consent_at->toDateString())->toBe('2026-01-01')
        ->and($merged->is_vip)->toBeFalse()
        ->and($merged->blacklisted_at)->toBeNull()
        ->and($merged->blacklist_reason)->toBeNull()
        ->and($merged->marketing_consent_at)->toBeNull();

    $keeper = Guest::factory()->for($property)->blacklisted('Own reason')->create();
    $other = Guest::factory()->for($property)->blacklisted('Other reason')->create();
    $action($owner, $keeper, $other);

    expect($keeper->refresh()->blacklist_reason)->toBe('Own reason');
});

test('refused merges return false and write nothing', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $action = app(MergeGuests::class);
    $a = Guest::factory()->for($property)->create();
    $b = Guest::factory()->for($property)->create();
    $foreign = Guest::factory()->create();
    $anonymized = Guest::factory()->for($property)->create(['anonymized_at' => now()]);
    $alreadyMerged = Guest::factory()->for($property)->create(['merged_into_id' => $b->id]);

    expect($action($owner, $a, $a))->toBeFalse()
        ->and($action($owner, $a, $foreign))->toBeFalse()
        ->and($action($owner, $a, $anonymized))->toBeFalse()
        ->and($action($owner, $anonymized, $a))->toBeFalse()
        ->and($action($owner, $a, $alreadyMerged))->toBeFalse()
        ->and($action($owner, $alreadyMerged, $a))->toBeFalse()
        ->and(Activity::query()->where('event', 'guest.merged')->count())->toBe(0);

    $this->actingAs($owner)->post(mergeUrl($owner, $a), ['duplicate_id' => $a->id])
        ->assertInertiaFlash('toast.type', 'error');
    $this->post(mergeUrl($owner, $a), ['duplicate_id' => $alreadyMerged->id])->assertInertiaFlash('toast.type', 'error');
    $this->post(mergeUrl($owner, $a), ['duplicate_id' => $foreign->id])->assertSessionHasErrors('duplicate_id');
    $this->post(mergeUrl($owner, $a), [])->assertSessionHasErrors('duplicate_id');
});

test('earlier merges are re-pointed so a merged guest always points to the final guest', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $action = app(MergeGuests::class);
    [$first, $second, $third] = Guest::factory()->for($property)->count(3)->create();

    $action($owner, $second, $first);
    $action($owner, $third, $second);

    expect($first->refresh()->merged_into_id)->toBe($third->id)
        ->and($second->refresh()->merged_into_id)->toBe($third->id);

    $this->actingAs($owner)->get(route('guests.show', [$property->code, $first]))
        ->assertRedirect(route('guests.show', [$property->code, $third]))
        ->assertInertiaFlash('toast.type', 'info');
});

test('a merged guest\'s pages redirect to the survivor and other requests are 404', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create();
    app(MergeGuests::class)($owner, $survivor, $merged);
    $code = $property->code;

    $this->actingAs($owner);
    $this->get(route('guests.notes.index', [$code, $merged]))->assertRedirect(route('guests.notes.index', [$code, $survivor]));
    $this->get(route('guests.documents.index', [$code, $merged]))->assertRedirect(route('guests.documents.index', [$code, $survivor]));
    $this->get(route('guests.edit', [$code, $merged]))->assertRedirect(route('guests.edit', [$code, $survivor]));
    $this->post(route('guests.vip.store', [$code, $merged]))->assertNotFound();
    $this->put(route('guests.update', [$code, $merged]), ['full_name' => 'X'])->assertNotFound();
    $this->post(route('guests.notes.store', [$code, $merged]), ['body' => 'x'])->assertNotFound();
});

test('the guest page lists possible duplicates and the merge permission is shown', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create(['full_name' => 'Aisyah Rahman', 'phone' => '+60123456789']);
    GuestIdentityDocument::factory()->for($guest)->create(['number' => '900101141234']);
    $byPhone = Guest::factory()->for($property)->create(['full_name' => 'Someone', 'phone' => '+60123456789']);
    $byId = Guest::factory()->for($property)->create(['full_name' => 'Another', 'phone' => null]);
    GuestIdentityDocument::factory()->for($byId)->create(['number' => '900101141234']);
    Guest::factory()->for($property)->create(['full_name' => 'Rahman Aisyah', 'phone' => null]);

    $this->actingAs($owner)->get(route('guests.show', [$property->code, $guest]))
        ->assertInertia(fn (Assert $page) => $page->component('guests/show')
            ->has('duplicates', 3)
            ->where('duplicates.0.isStrong', true)
            ->where('duplicates.2.fullName', 'Rahman Aisyah')
            ->where('duplicates.2.isStrong', false)
            ->where('canMerge', true));

    $frontDesk = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->create();
    $this->actingAs($frontDesk)->get(route('guests.show', [$property->code, $guest]))
        ->assertInertia(fn (Assert $page) => $page->where('canMerge', false)->has('duplicates', 3));

    $finance = User::factory()->for($property)->withRole(DefaultRoleEnum::Finance)->create();
    $this->actingAs($finance)->get(route('guests.show', [$property->code, $guest]))
        ->assertInertia(fn (Assert $page) => $page->has('duplicates', 2));
});

test('only Owner and Manager can merge and another property\'s guest returns 404', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $survivor = Guest::factory()->for($property)->create();
    $duplicate = Guest::factory()->for($property)->create();

    foreach ([DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Finance, DefaultRoleEnum::Housekeeping] as $role) {
        $user = User::factory()->for($property)->withRole($role)->create();
        $this->actingAs($user)->post(mergeUrl($user, $survivor), ['duplicate_id' => $duplicate->id])->assertForbidden();
    }

    $manager = User::factory()->for($property)->withRole(DefaultRoleEnum::Manager)->create();
    $this->actingAs($manager)->post(mergeUrl($manager, $survivor), ['duplicate_id' => $duplicate->id])->assertInertiaFlash('toast.type', 'success');

    $foreign = Guest::factory()->create();
    $this->actingAs($owner)->post(route('guests.merge.store', [$property->code, $foreign]), ['duplicate_id' => $survivor->id])->assertNotFound();
    expect($duplicate->refresh()->merged_into_id)->toBe($survivor->id);
});
