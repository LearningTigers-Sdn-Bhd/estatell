<?php

use App\Enums\IdentityDocumentType;
use App\Models\Guest;
use App\Models\ImportLink;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);
});

function runGuestImport(array $rows, array $options = []): PendingCommand
{
    $bundle = wastaysBundleDir(['guests' => $rows]);

    return test()->artisan('wastays:import-guests', [
        'bundle' => $bundle,
        'property' => test()->property->code,
        '--actor' => test()->admin->email,
        ...$options,
    ]);
}

it('maps a full row, its MyKad and the import link', function () {
    runGuestImport([[
        'id' => '7', 'name' => 'Aisha Binti Ali', 'email' => 'aisha@example.com', 'phone' => '012-345 6789',
        'country' => 'Malaysia', 'address_country' => 'malaysia', 'home_address' => '1 Jalan Satu', 'city' => 'Kuching',
        'postal_code' => '93000', 'state_code' => 'Sarawak', 'date_of_birth' => '1990-05-04', 'gender' => 'female',
        'document_type' => 'malaysian_nric', 'government_id' => '900504-13-5566',
    ]])->assertSuccessful();

    $guest = Guest::query()->where('property_id', $this->property->id)->sole();

    expect($guest->full_name)->toBe('Aisha Binti Ali')
        ->and($guest->email)->toBe('aisha@example.com')
        ->and($guest->phone)->toBe('+60123456789')
        ->and($guest->nationality_code)->toBe('MY')
        ->and($guest->country_code)->toBe('MY')
        ->and($guest->address_line1)->toBe('1 Jalan Satu')
        ->and($guest->city)->toBe('Kuching')
        ->and($guest->postal_code)->toBe('93000')
        ->and($guest->state)->toBe('Sarawak')
        ->and($guest->date_of_birth->toDateString())->toBe('1990-05-04')
        ->and($guest->gender->value)->toBe('female');

    $document = $guest->documents()->sole();
    expect($document->type)->toBe(IdentityDocumentType::MyKad)->and($document->number)->toBe('900504135566');

    expect(ImportLink::query()->where(['property_id' => $this->property->id, 'source_table' => 'guests', 'source_id' => '7'])->value('target_id'))
        ->toBe($guest->id);
});

it('keeps an unreadable phone as a note and drops an invalid email and an unknown country', function () {
    runGuestImport([[
        'id' => '1', 'name' => 'Lim', 'email' => 'not-an-email', 'phone' => '12', 'country' => 'Atlantis',
    ]])->assertSuccessful()
        ->expectsTable(['Count', 'Guests'], collect([
            ['created', 1], ['already linked', 0], ['invalid emails dropped', 1], ['phones kept as notes', 1],
            ['unmatched countries', 1], ['ID documents: MyKad', 0], ['ID documents: Passport', 0],
            ['ID documents: Other', 0], ['VIP', 0], ['blacklisted', 0],
        ])->all());

    $guest = Guest::query()->sole();
    expect($guest->email)->toBeNull()
        ->and($guest->phone)->toBeNull()
        ->and($guest->nationality_code)->toBeNull()
        ->and($guest->notes()->sole()->body)->toBe('wastays phone: 12');
});

it('reads a phone with a country code as it is', function () {
    runGuestImport([['id' => '1', 'name' => 'Wang', 'phone' => '+86 138 0013 8000', 'country' => 'China']])->assertSuccessful();

    expect(Guest::query()->sole())->phone->toBe('+8613800138000')->nationality_code->toBe('CN');
});

it('imports passports and infers the type when wastays has none', function () {
    runGuestImport([
        ['id' => '1', 'name' => 'A', 'document_type' => 'passport', 'passport_number' => 'e1234567', 'passport_issuing_country_code' => 'PHL', 'government_id' => null],
        ['id' => '2', 'name' => 'B', 'government_id' => '910203-14-1234'],
        ['id' => '3', 'name' => 'C', 'document_type' => 'null', 'government_id' => 'AB 12345'],
        ['id' => '4', 'name' => 'D'],
    ])->assertSuccessful();

    $documents = fn (string $name) => Guest::query()->where('full_name', $name)->sole()->documents;

    expect($documents('A')->sole())->type->toBe(IdentityDocumentType::Passport)->number->toBe('E1234567')->issuing_country_code->toBe('PH')
        ->and($documents('B')->sole())->type->toBe(IdentityDocumentType::MyKad)->number->toBe('910203141234')
        ->and($documents('C')->sole())->type->toBe(IdentityDocumentType::Other)->number->toBe('AB12345')
        ->and($documents('D'))->toBeEmpty();
});

it('sets VIP and blacklists with a reason', function () {
    runGuestImport([['id' => '1', 'name' => 'Vip', 'vip' => 'true', 'blacklisted' => 'true', 'discarded_at' => '2026-01-01 10:00:00']])->assertSuccessful();

    $guest = Guest::query()->sole();
    expect($guest->is_vip)->toBeTrue()
        ->and($guest->blacklisted_at)->not->toBeNull()
        ->and($guest->blacklist_reason)->toBe('Blacklisted in wastays');
});

it('skips guests that are already linked on a second run', function () {
    $rows = [['id' => '1', 'name' => 'One', 'phone' => '0123456789'], ['id' => '2', 'name' => 'Two']];
    $bundle = wastaysBundleDir(['guests' => $rows]);
    $args = ['bundle' => $bundle, 'property' => $this->property->code, '--actor' => $this->admin->email];

    $this->artisan('wastays:import-guests', $args)->assertSuccessful();
    $this->artisan('wastays:import-guests', $args)->assertSuccessful();

    expect(Guest::query()->count())->toBe(2)->and(ImportLink::query()->count())->toBe(2);
});

it('writes nothing on a dry run', function () {
    runGuestImport([['id' => '1', 'name' => 'One', 'government_id' => '910203141234', 'vip' => 'true']], ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry run');

    expect(Guest::query()->count())->toBe(0)->and(ImportLink::query()->count())->toBe(0);
});

it('prints counts only, never personal data', function () {
    $this->withoutMockingConsoleOutput();
    $bundle = wastaysBundleDir(['guests' => [['id' => '1', 'name' => 'Zainal Secret', 'email' => 'zainal@secret.test', 'phone' => '0129998888', 'government_id' => '910203141234']]]);

    $exit = Artisan::call('wastays:import-guests', ['bundle' => $bundle, 'property' => $this->property->code, '--actor' => $this->admin->email]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('Zainal')->not->toContain('secret.test')->not->toContain('9998888')->not->toContain('910203141234');
});

it('refuses with a message when the property has the wrong prefix', function () {
    $property = Property::factory()->onboarding()->create(['document_prefix' => 'ABC']);

    $this->artisan('wastays:import-guests', ['bundle' => wastaysBundleDir(['guests' => [['id' => '1', 'name' => 'X']]]), 'property' => $property->code, '--actor' => $this->admin->email])
        ->assertFailed()
        ->expectsOutputToContain('The bundle is for GEH');

    expect(Guest::query()->count())->toBe(0);
});
