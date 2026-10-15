<?php

use App\Actions\Guests\AddGuestNote;
use App\Actions\Guests\AnonymizeGuest;
use App\Enums\DefaultRoleEnum;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\GuestNote;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tag;
use App\Models\UnitGuest;
use App\Models\User;
use App\Services\Guests\GuestName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('local', ['serve' => true]);
});

function personalGuest(User $user): Guest
{
    $guest = Guest::factory()->for($user->property)->vip()->create([
        'full_name' => 'Aisyah binti Rahman',
        'email' => 'aisyah@example.com',
        'phone' => '+60123456789',
        'date_of_birth' => '1990-01-01',
        'gender' => 'female',
        'preferred_locale' => 'ms',
        'address_line1' => '12 Jalan Bukit Bintang',
        'city' => 'Kuala Lumpur',
        'state' => 'Wilayah Persekutuan',
        'postal_code' => '55100',
        'marketing_consent_at' => now(),
    ]);
    $guest->tags()->attach(Tag::factory()->for($user->property)->create(['name' => 'Corporate']));
    GuestNote::factory()->for($guest)->count(2)->create();
    $document = GuestIdentityDocument::factory()->for($guest)->create(['number' => '900101141234']);
    $document->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('id_front');

    return $guest;
}

test('anonymizing removes personal data and keeps the record', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = personalGuest($owner);
    $path = GuestIdentityDocument::query()->sole()->getFirstMedia('id_front')->getPathRelativeToRoot();
    $tag = Tag::query()->sole();

    $this->actingAs($owner)
        ->post(route('guests.anonymize.store', [$property->code, $guest]))
        ->assertRedirect(route('guests.show', [$property->code, $guest]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Aisyah binti Rahman archived.']);

    $guest->refresh();

    expect($guest->full_name)->toBe("Archived User #{$guest->id}")
        ->and($guest->name_key)->toBe("{$guest->id} archived user")
        ->and($guest->anonymized_at)->not->toBeNull()
        ->and($guest->property_id)->toBe($property->id)
        ->and($guest->is_vip)->toBeFalse()
        ->and([$guest->email, $guest->phone, $guest->date_of_birth, $guest->gender, $guest->preferred_locale, $guest->nationality_code, $guest->address_line1, $guest->city, $guest->state, $guest->postal_code, $guest->country_code, $guest->blacklisted_at, $guest->blacklist_reason, $guest->marketing_consent_at])->each->toBeNull()
        ->and($guest->notes()->count())->toBe(0)
        ->and($guest->tags()->count())->toBe(0)
        ->and($tag->refresh()->name)->toBe('Corporate')
        ->and(GuestIdentityDocument::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and(DB::table('blind_indexes')->count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();

    $log = Activity::query()->where('event', 'guest.anonymized')->sole();

    expect($log->properties['notes_removed'])->toBe(2)
        ->and($log->properties['documents_removed'])->toBe(1)
        ->and(json_encode($log->properties))->not->toContain('Aisyah')->not->toContain('900101');
});

test('the blacklist reason is removed from earlier log entries', function () {
    $owner = User::factory()->owner()->create();
    $guest = Guest::factory()->for($owner->property)->create();

    $this->actingAs($owner)->post(route('guests.blacklist.store', [$owner->property->code, $guest]), ['reason' => 'Threatened Mr Lim at the desk']);
    expect(json_encode(Activity::query()->where('event', 'guest.blacklisted')->sole()->properties))->toContain('Mr Lim');

    app(AnonymizeGuest::class)($owner, $guest);

    $entry = Activity::query()->where('event', 'guest.blacklisted')->sole();

    expect($entry->properties->has('reason'))->toBeFalse()
        ->and($entry->properties['property_id'])->toBe($owner->property_id);
});

test('refused anonymizing returns false and writes nothing', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $action = app(AnonymizeGuest::class);
    $done = Guest::factory()->for($property)->create(['anonymized_at' => now()]);
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create(['merged_into_id' => $survivor->id]);

    expect($action($owner, $done))->toBeFalse()
        ->and($action($owner, $merged))->toBeFalse()
        ->and(Activity::query()->where('event', 'guest.anonymized')->count())->toBe(0);

    $this->actingAs($owner)->post(route('guests.anonymize.store', [$property->code, $done]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => "{$done->full_name} cannot be archived."]);
});

test('an anonymized guest cannot be changed', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create(['anonymized_at' => now()]);
    $code = $property->code;

    $this->actingAs($owner);
    $this->put(route('guests.update', [$code, $guest]), ['full_name' => 'Back again'])->assertInertiaFlash('toast.type', 'error');
    $this->post(route('guests.vip.store', [$code, $guest]))->assertInertiaFlash('toast.type', 'error');
    $this->post(route('guests.blacklist.store', [$code, $guest]), ['reason' => 'x'])->assertInertiaFlash('toast.type', 'error');
    $this->post(route('guests.notes.store', [$code, $guest]), ['body' => 'x'])->assertInertiaFlash('toast.type', 'error');
    $this->post(route('guests.documents.store', [$code, $guest]), ['type' => 'passport', 'number' => 'A1234567'])->assertInertiaFlash('toast.type', 'error');
    $this->get(route('guests.show', [$code, $guest]))->assertInertia(fn ($page) => $page->where('formOptions', null));

    expect(app(AddGuestNote::class)($owner, $guest, 'x'))->toBeNull()
        ->and($guest->notes()->count())->toBe(0)
        ->and($guest->documents()->count())->toBe(0)
        ->and($guest->refresh()->full_name)->not->toBe('Back again');
});

test('the guest page shows the anonymized date and the list leaves the guest out unless asked', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create(['anonymized_at' => '2026-10-01 10:00:00']);
    Guest::factory()->for($property)->create(['full_name' => 'Active Guest']);

    $this->actingAs($owner)->get(route('guests.show', [$property->code, $guest]))
        ->assertInertia(fn (Assert $page) => $page->where('guest.anonymizedAt', '2026-10-01')->where('canAnonymize', true));

    $url = route('guests.index', $property->code);

    $this->get($url)->assertInertia(fn (Assert $page) => $page->has('guests.data', 1)->where('guests.data.0.fullName', 'Active Guest')->where('guests.data.0.isAnonymized', false)->where('statuses.2', ['value' => 'anonymized', 'label' => 'Archived']));
    $this->get("{$url}?filter[status]=anonymized")->assertInertia(fn (Assert $page) => $page->has('guests.data', 1)->where('guests.data.0.id', $guest->id)->where('guests.data.0.isAnonymized', true));
});

test('only the Owner can anonymize and another property\'s guest returns 404', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create();

    foreach ([DefaultRoleEnum::Manager, DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Finance, DefaultRoleEnum::Housekeeping] as $role) {
        $user = User::factory()->for($property)->withRole($role)->create();
        $this->actingAs($user)->post(route('guests.anonymize.store', [$property->code, $guest]))->assertForbidden();
        if ($role !== DefaultRoleEnum::Housekeeping) {
            $this->get(route('guests.show', [$property->code, $guest]))->assertInertia(fn (Assert $page) => $page->where('canAnonymize', false));
        }
    }

    expect($guest->refresh()->anonymized_at)->toBeNull();

    $this->actingAs($owner)->post(route('guests.anonymize.store', [$property->code, Guest::factory()->create()]))->assertNotFound();
});

test('an in-house guest cannot be anonymized and nothing is written', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = personalGuest($owner);
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($property))->checkedIn()->create();
    UnitGuest::factory()->for($unit)->create(['guest_id' => $guest->id]);

    $this->actingAs($owner)
        ->post(route('guests.anonymize.store', [$property->code, $guest]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Aisyah binti Rahman cannot be archived.']);

    expect($guest->refresh()->anonymized_at)->toBeNull()
        ->and($guest->full_name)->toBe('Aisyah binti Rahman')
        ->and($guest->notes()->count())->toBe(2)
        ->and($guest->documents()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'guest.anonymized')->count())->toBe(0);
});

test('a guest who is not checked in can be anonymized', function (string $state) {
    $owner = User::factory()->owner()->create();
    $guest = Guest::factory()->for($owner->property)->create();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($owner->property))->{$state}()->create();
    UnitGuest::factory()->for($unit)->create(['guest_id' => $guest->id]);

    expect(app(AnonymizeGuest::class)($owner, $guest))->toBeTrue();
})->with(['checkedOut', 'cancelled', 'noShow']);

test('a booker who does not stay can be anonymized while the reservation is in house', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $booker = Guest::factory()->for($property)->create();
    $occupant = Guest::factory()->for($property)->create();
    $reservation = Reservation::factory()->for($property)->create(['booker_guest_id' => $booker->id]);
    $unit = ReservationUnit::factory()->for($reservation)->checkedIn()->create();
    UnitGuest::factory()->for($unit)->create(['guest_id' => $occupant->id]);

    expect(app(AnonymizeGuest::class)($owner, $booker))->toBeTrue()
        ->and(app(AnonymizeGuest::class)($owner, $occupant))->toBeFalse();
});

test('the placeholder migration renames only anonymized placeholders and keeps their history', function () {
    $property = User::factory()->owner()->create()->property;
    $archived = Guest::factory()->for($property)->create(['anonymized_at' => '2026-10-01 10:00:00']);
    $oldName = "Anonymized guest #{$archived->id}";
    $archived->update(['full_name' => $oldName, 'name_key' => app(GuestName::class)->key($oldName)]);
    $unchangedTimestamp = $archived->updated_at;
    $active = Guest::factory()->for($property)->create(['full_name' => 'Anonymized guest #999']);
    $custom = Guest::factory()->for($property)->create(['full_name' => 'Already private', 'anonymized_at' => now()]);
    $reservation = Reservation::factory()->for($property)->create(['booker_guest_id' => $archived->id]);
    $migration = require database_path('migrations/2026_10_15_072124_rename_anonymized_guest_placeholders.php');

    $migration->up();
    $migration->up();

    $this->assertDatabaseHas('guests', [
        'id' => $archived->id,
        'full_name' => "Archived User #{$archived->id}",
        'name_key' => "{$archived->id} archived user",
        'anonymized_at' => '2026-10-01 10:00:00',
        'updated_at' => $unchangedTimestamp,
    ]);
    $this->assertDatabaseHas('guests', ['id' => $active->id, 'full_name' => $active->full_name, 'name_key' => $active->name_key]);
    $this->assertDatabaseHas('guests', ['id' => $custom->id, 'full_name' => $custom->full_name, 'name_key' => $custom->name_key]);
    $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'booker_guest_id' => $archived->id]);
    expect(Activity::query()->count())->toBe(0);

    $migration->down();

    $this->assertDatabaseHas('guests', [
        'id' => $archived->id,
        'full_name' => $oldName,
        'name_key' => "{$archived->id} anonymized guest",
        'anonymized_at' => '2026-10-01 10:00:00',
    ]);
});
