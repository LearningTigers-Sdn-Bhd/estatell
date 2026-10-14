<?php

use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\UpdateGuest;
use App\Enums\DefaultRoleEnum;
use App\Enums\GuestMatchReason;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\User;
use App\Services\Guests\GuestMatcher;
use App\Services\Guests\GuestName;

/**
 * @return list<string>
 */
function matchedNames(array $matches): array
{
    return array_map(fn (array $match): string => $match['guest']->full_name, $matches);
}

/**
 * @return list<string>
 */
function reasonValues(array $match): array
{
    return array_map(fn (GuestMatchReason $reason): string => $reason->value, $match['reasons']);
}

test('names are brought to one comparable key', function (string $name, string $key) {
    expect(app(GuestName::class)->key($name))->toBe($key);
})->with([
    'case and spaces' => ['  AISYAH   Rahman ', 'aisyah rahman'],
    'word order' => ['Rahman, Aisyah', 'aisyah rahman'],
    'binti' => ['Aisyah binti Rahman', 'aisyah rahman'],
    'bin and dots' => ['Ahmad bin Ali', 'ahmad ali'],
    'abbreviations' => ['Ahmad b. Ali', 'ahmad ali'],
    'bt' => ['Siti Aminah bt. Hassan', 'aminah hassan siti'],
    'a/l' => ['Kumar a/l Raju', 'kumar raju'],
    'accents' => ['José Müller', 'jose muller'],
    'a name with bin inside a word is kept' => ['Robin Binu', 'binu robin'],
    'nothing left falls back to the name' => ['bin', 'bin'],
]);

test('creating and updating a guest keeps the name key in step', function () {
    $owner = User::factory()->owner()->create();

    $guest = app(CreateGuest::class)($owner, $owner->property, ['full_name' => 'Aisyah binti Rahman']);
    expect($guest->refresh()->name_key)->toBe('aisyah rahman');

    app(UpdateGuest::class)($owner, $guest, ['full_name' => 'Siti Nur']);
    expect($guest->refresh()->name_key)->toBe('nur siti');
});

test('guests are found by phone, by email ignoring case and by name', function () {
    $property = Property::factory()->create();
    $byPhone = Guest::factory()->for($property)->create(['full_name' => 'Phone Person', 'phone' => '+60123456789', 'email' => null]);
    $byEmail = Guest::factory()->for($property)->create(['full_name' => 'Email Person', 'email' => 'Aisyah@Example.com', 'phone' => null]);
    $byName = Guest::factory()->for($property)->create(['full_name' => 'Rahman Aisyah', 'email' => null, 'phone' => null]);
    Guest::factory()->for($property)->create(['full_name' => 'Unrelated', 'phone' => '+60111111111', 'email' => 'other@example.com']);

    $matcher = app(GuestMatcher::class);

    $matches = $matcher->find($property, name: 'Aisyah binti Rahman', email: ' aisyah@example.com ', phone: '012-345 6789');

    expect(matchedNames($matches))->toContain('Phone Person', 'Email Person', 'Rahman Aisyah')->not->toContain('Unrelated')
        ->and(collect($matches)->firstWhere(fn (array $m) => $m['guest']->is($byPhone)))->toMatchArray(['reasons' => [GuestMatchReason::Phone]])
        ->and(reasonValues(collect($matches)->firstWhere(fn (array $m) => $m['guest']->is($byEmail))))->toBe(['email'])
        ->and(reasonValues(collect($matches)->firstWhere(fn (array $m) => $m['guest']->is($byName))))->toBe(['name']);

    expect($matcher->find($property))->toBe([])
        ->and($matcher->find($property, phone: 'not a number', email: ''))->toBe([]);
});

test('name and date of birth is strong and a name alone is weak and sorted last', function () {
    $property = Property::factory()->create();
    Guest::factory()->for($property)->create(['full_name' => 'Tan Wei Ming', 'date_of_birth' => '1985-06-15', 'phone' => null, 'email' => null]);
    Guest::factory()->for($property)->create(['full_name' => 'Wei Ming Tan', 'date_of_birth' => '1990-01-01', 'phone' => null, 'email' => null]);
    Guest::factory()->for($property)->create(['full_name' => 'Zed Phone', 'phone' => '+60123456789', 'email' => null]);

    $matches = app(GuestMatcher::class)->find($property, name: 'Tan Wei Ming', phone: '+60123456789', dateOfBirth: '1985-06-15');

    expect(matchedNames($matches))->toBe(['Tan Wei Ming', 'Zed Phone', 'Wei Ming Tan'])
        ->and(reasonValues($matches[0]))->toBe(['name_and_birth_date'])
        ->and(reasonValues($matches[2]))->toBe(['name'])
        ->and(GuestMatchReason::Name->isStrong())->toBeFalse()
        ->and(GuestMatchReason::NameAndBirthDate->isStrong())->toBeTrue();
});

test('merged and anonymized guests and other properties are never returned, and blacklisted guests are', function () {
    $property = Property::factory()->create();
    $survivor = Guest::factory()->for($property)->create();
    Guest::factory()->for($property)->create(['phone' => '+60123456789', 'merged_into_id' => $survivor->id]);
    Guest::factory()->for($property)->create(['phone' => '+60123456789', 'anonymized_at' => now()]);
    Guest::factory()->create(['phone' => '+60123456789']);
    $blacklisted = Guest::factory()->for($property)->blacklisted()->create(['phone' => '+60123456789']);

    $matcher = app(GuestMatcher::class);

    expect($matcher->find($property, phone: '+60123456789'))->toHaveCount(1)
        ->and($matcher->find($property, phone: '+60123456789')[0]['guest']->is($blacklisted))->toBeTrue()
        ->and($matcher->find($property, phone: '+60123456789', excludeGuestId: $blacklisted->id))->toBe([]);
});

test('ID numbers match only when the caller may use them', function () {
    $property = Property::factory()->create();
    $holder = Guest::factory()->for($property)->create(['phone' => null, 'email' => null]);
    GuestIdentityDocument::factory()->for($holder)->create(['number' => '900101141234']);
    $matcher = app(GuestMatcher::class);

    expect($matcher->find($property, idNumbers: ['900101-14-1234'], includeIdNumbers: true))->toHaveCount(1)
        ->and(reasonValues($matcher->find($property, idNumbers: ['900101-14-1234'], includeIdNumbers: true)[0]))->toBe(['id_number'])
        ->and($matcher->find($property, idNumbers: ['900101-14-1234']))->toBe([])
        ->and($matcher->find($property, idNumbers: ['900101141235'], includeIdNumbers: true))->toBe([])
        ->and($matcher->find($property, idNumbers: ['123'], includeIdNumbers: true))->toBe([]);
});

test('possible duplicates of an existing guest use its own details and documents', function () {
    $property = Property::factory()->create();
    $guest = Guest::factory()->for($property)->create(['full_name' => 'Aisyah Rahman', 'phone' => null, 'email' => null]);
    GuestIdentityDocument::factory()->for($guest)->create(['number' => '900101141234']);
    $twin = Guest::factory()->for($property)->create(['full_name' => 'Someone Else', 'phone' => null, 'email' => null]);
    GuestIdentityDocument::factory()->for($twin)->create(['number' => '900101141234']);
    $sameName = Guest::factory()->for($property)->create(['full_name' => 'Rahman Aisyah', 'phone' => null, 'email' => null]);

    $guest->load('documents');
    $matcher = app(GuestMatcher::class);

    expect(matchedNames($matcher->forGuest($guest, true)))->toBe(['Someone Else', 'Rahman Aisyah'])
        ->and(matchedNames($matcher->forGuest($guest, false)))->toBe(['Rahman Aisyah']);
});

test('the matches endpoint returns suggestions, skips the excluded guest and enforces guests.view', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $mine = Guest::factory()->for($property)->vip()->create(['full_name' => 'Aisyah', 'phone' => '+60123456789']);
    $other = Guest::factory()->for($property)->create(['full_name' => 'Aisyah', 'phone' => '+60123456789']);
    GuestIdentityDocument::factory()->for($other)->create(['number' => 'A12345678']);
    $url = route('guests.matches', $property->code);

    $this->actingAs($owner)->postJson($url, ['phone' => '012 345 6789'])
        ->assertOk()
        ->assertJsonCount(2, 'matches')
        ->assertJsonPath('matches.0.isStrong', true)
        ->assertJsonStructure(['matches' => [['id', 'fullName', 'email', 'phone', 'isVip', 'isBlacklisted', 'reasons', 'isStrong', 'profile' => ['nationalityCode', 'dateOfBirth', 'gender', 'preferredLocale', 'addressLine1', 'addressLine2', 'city', 'state', 'postalCode', 'countryCode', 'blacklistReason']]]]);

    $this->postJson($url, ['phone' => '012 345 6789', 'exclude_guest_id' => $mine->id])
        ->assertJsonCount(1, 'matches')->assertJsonPath('matches.0.id', $other->id);

    $this->postJson($url, ['id_numbers' => ['A12345678']])->assertJsonPath('matches.0.reasons.0', 'Same ID number');
    $this->postJson($url, ['date_of_birth' => 'tomorrow'])->assertUnprocessable();

    $housekeeping = User::factory()->for($property)->withRole(DefaultRoleEnum::Housekeeping)->create();
    $this->actingAs($housekeeping)->postJson($url, ['phone' => '012 345 6789'])->assertForbidden();

    $finance = User::factory()->for($property)->withRole(DefaultRoleEnum::Finance)->create();
    $this->actingAs($finance)->postJson($url, ['id_numbers' => ['A12345678']])->assertOk()->assertJsonCount(0, 'matches');

    $foreign = User::factory()->owner()->create();
    $this->actingAs($foreign)->postJson(route('guests.matches', $foreign->property->code), ['phone' => '012 345 6789'])->assertJsonCount(0, 'matches');
});
