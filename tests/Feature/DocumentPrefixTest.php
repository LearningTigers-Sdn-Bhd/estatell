<?php

use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Actions\Property\GenerateDocumentPrefix;
use App\Models\Property;
use App\Models\PropertyDocumentPrefixHistory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function propertyNamed(string $name): Property
{
    return Property::factory()->create(['name' => $name]);
}

test('the prefix comes from the name', function (string $name, string $prefix) {
    expect(propertyNamed($name)->document_prefix)->toBe($prefix);
})->with([
    'three words' => ['Grand River Hotel', 'GRH'],
    'four or more words keep four initials' => ['The Grand River Beach Resort', 'TGRB'],
    'two words' => ['Pulau Inn', 'PUI'],
    'two short words' => ['A B', 'AXB'],
    'one word' => ['Mutiara', 'MUTI'],
    'a short word is padded' => ['Ox', 'OXX'],
    'digits and punctuation are dropped' => ['D\'Palma 88 Hotel', 'DPH'],
    'accents are dropped' => ['Café Résidence Hôtel', 'CRH'],
    'nothing usable' => ['123 456', 'EST'],
]);

test('a taken prefix gets a number', function () {
    $first = propertyNamed('Grand River Hotel');
    $second = propertyNamed('Green Rock House');
    $third = propertyNamed('Golden Reef Hostel');

    expect($first->document_prefix)->toBe('GRH')
        ->and($second->document_prefix)->toBe('GRH2')
        ->and($third->document_prefix)->toBe('GRH3');
});

test('a prefix is recorded in the history', function () {
    $property = propertyNamed('Grand River Hotel');

    expect(PropertyDocumentPrefixHistory::query()->sole()->only(['property_id', 'prefix']))
        ->toBe(['property_id' => $property->id, 'prefix' => 'GRH']);
});

test('a retired prefix is never given to another property', function () {
    PropertyDocumentPrefixHistory::factory()->create(['prefix' => 'GRH']);

    expect(propertyNamed('Grand River Hotel')->document_prefix)->toBe('GRH2');
});

test('a prefix never changes once set', function () {
    $property = propertyNamed('Grand River Hotel');
    $property->update(['name' => 'Sunrise Beach Resort']);

    expect(app(GenerateDocumentPrefix::class)($property))->toBe('GRH')
        ->and($property->refresh()->document_prefix)->toBe('GRH')
        ->and(PropertyDocumentPrefixHistory::query()->count())->toBe(1);
});

test('the database keeps prefixes unique and well formed', function () {
    propertyNamed('Grand River Hotel');
    $other = propertyNamed('Pulau Inn');

    expect(fn () => DB::table('properties')->where('id', $other->id)->update(['document_prefix' => 'GRH']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('properties')->where('id', $other->id)->update(['document_prefix' => 'ab']))
        ->toThrow(QueryException::class);
});

test('a property that signs up gets its prefix', function () {
    $owner = app(RegisterPropertyOwner::class)([
        'name' => 'Aisha',
        'email' => 'aisha@example.test',
        'password' => 'password-123',
        'property_name' => 'Pulau Indah Resort',
    ]);

    expect($owner->property->document_prefix)->toBe('PIR');
});
