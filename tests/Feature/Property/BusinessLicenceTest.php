<?php

use App\Actions\Property\SavePropertyBusiness;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\PropertyLicence;
use App\Models\PropertySetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property} */
function businessProperty(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->update(['country_code' => 'MY']);
    PropertySetting::factory()->for($property)->create();

    return [$owner, $property];
}

/** @return array<string, mixed> */
function businessPayload(array $overrides = []): array
{
    return [
        'legal_name' => 'Seaview Hospitality Sdn Bhd',
        'registration_number' => '202301012345',
        'tin' => 'C1234567890',
        'licences' => [],
        ...$overrides,
    ];
}

test('the tab sends the legal details with the country labels and this property\'s licences in order', function () {
    [$owner, $property] = businessProperty();
    $property->update(['legal_name' => 'Seaview Hospitality Sdn Bhd', 'registration_number' => '202301012345']);
    PropertyLicence::factory()->for($property)->create(['issuer' => 'MOTAC', 'number' => 'M-2', 'position' => 1]);
    PropertyLicence::factory()->for($property)->create(['issuer' => 'DBKK', 'number' => 'D-1', 'expires_on' => '2027-03-31', 'show_on_documents' => true, 'position' => 0]);
    PropertyLicence::factory()->create(['issuer' => 'Other property']);

    $this->actingAs($owner)
        ->get(route('settings.business', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/edit')
            ->where('tab', 'business')
            ->where('business.legalName', 'Seaview Hospitality Sdn Bhd')
            ->where('business.registrationNumberLabel', 'SSM registration no.')
            ->where('business.tinLabel', 'TIN (LHDN)')
            ->has('business.licences', 2)
            ->where('business.licences.0.issuer', 'DBKK')
            ->where('business.licences.0.expiresOn', '2027-03-31')
            ->where('business.licences.0.showOnDocuments', true)
            ->where('business.licences.1.issuer', 'MOTAC'));
});

test('staff save the business details and licences in list order, and the change is logged', function () {
    [$owner, $property] = businessProperty();

    $this->actingAs($owner)
        ->put(route('settings.business.update', $property->code), businessPayload([
            'licences' => [
                ['id' => null, 'issuer' => '  DBKK ', 'number' => 'D-1', 'expires_on' => '2027-03-31', 'show_on_documents' => true],
                ['id' => null, 'issuer' => 'MOTAC', 'number' => 'M-2', 'expires_on' => null, 'show_on_documents' => false],
            ],
        ]))
        ->assertRedirect(route('settings.business', $property->code))
        ->assertSessionHasNoErrors();

    $property->refresh();
    $licences = $property->licences()->ordered()->get();
    $activity = Activity::query()->where('event', 'property.updated')->sole();

    expect($property->legal_name)->toBe('Seaview Hospitality Sdn Bhd')
        ->and($property->registration_number)->toBe('202301012345')
        ->and($property->tin)->toBe('C1234567890')
        ->and($licences->pluck('issuer')->all())->toBe(['DBKK', 'MOTAC'])
        ->and($licences[0]->expires_on->toDateString())->toBe('2027-03-31')
        ->and($licences[0]->show_on_documents)->toBeTrue()
        ->and($licences[1]->show_on_documents)->toBeFalse()
        ->and($activity->properties['changed'])->toEqualCanonicalizing(['legal_name', 'registration_number', 'tin', 'licences']);
});

test('a save replaces the list: rows keep their id, new rows are added and missing rows are deleted', function () {
    [$owner, $property] = businessProperty();
    $keep = PropertyLicence::factory()->for($property)->create(['issuer' => 'DBKK', 'number' => 'D-1', 'position' => 0]);
    $drop = PropertyLicence::factory()->for($property)->create(['issuer' => 'Old', 'number' => 'O-1', 'position' => 1]);

    $this->actingAs($owner)
        ->put(route('settings.business.update', $property->code), businessPayload([
            'licences' => [
                ['id' => null, 'issuer' => 'MOTAC', 'number' => 'M-2'],
                ['id' => $keep->id, 'issuer' => 'DBKK', 'number' => 'D-9'],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $licences = $property->licences()->ordered()->get();

    expect($licences->pluck('issuer')->all())->toBe(['MOTAC', 'DBKK'])
        ->and($licences[1]->id)->toBe($keep->id)
        ->and($licences[1]->number)->toBe('D-9')
        ->and(PropertyLicence::query()->find($drop->id))->toBeNull();
});

test('a save that changes nothing is not logged, and empty text is stored as null', function () {
    [$owner, $property] = businessProperty();
    $property->update(['legal_name' => 'Seaview Hospitality Sdn Bhd', 'registration_number' => '202301012345', 'tin' => 'C1234567890']);

    $this->actingAs($owner)
        ->put(route('settings.business.update', $property->code), businessPayload())
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'property.updated')->count())->toBe(0);

    $this->put(route('settings.business.update', $property->code), businessPayload(['legal_name' => '', 'tin' => '']))
        ->assertSessionHasNoErrors();

    expect($property->refresh()->legal_name)->toBeNull()
        ->and($property->tin)->toBeNull();
});

test('every licence needs an issuer and a number, a real date and no more than twenty are allowed', function () {
    [$owner, $property] = businessProperty();

    $this->actingAs($owner)
        ->put(route('settings.business.update', $property->code), businessPayload([
            'licences' => [['id' => null, 'issuer' => ' ', 'number' => '', 'expires_on' => 'soon']],
        ]))
        ->assertSessionHasErrors(['licences.0.issuer', 'licences.0.number', 'licences.0.expires_on']);

    $this->put(route('settings.business.update', $property->code), businessPayload([
        'licences' => array_fill(0, 21, ['id' => null, 'issuer' => 'DBKK', 'number' => 'D-1']),
    ]))->assertSessionHasErrors('licences');

    expect($property->licences()->count())->toBe(0);
});

test('a licence id from another property is refused', function () {
    [$owner, $property] = businessProperty();
    $foreign = PropertyLicence::factory()->create(['issuer' => 'Foreign']);

    $this->actingAs($owner)
        ->put(route('settings.business.update', $property->code), businessPayload([
            'licences' => [['id' => $foreign->id, 'issuer' => 'Hijacked', 'number' => 'X-1']],
        ]))
        ->assertSessionHasErrors('licences.0.id');

    expect($foreign->refresh()->issuer)->toBe('Foreign');
});

test('the Action refuses an id that is not the property\'s and writes nothing', function () {
    [$owner, $property] = businessProperty();
    $foreign = PropertyLicence::factory()->create();

    $saved = app(SavePropertyBusiness::class)($owner, $property, ['legal_name' => 'Changed', 'registration_number' => null, 'tin' => null], [
        ['id' => $foreign->id, 'issuer' => 'X', 'number' => '1', 'expires_on' => null, 'show_on_documents' => false],
    ]);

    expect($saved)->toBeFalse()
        ->and($property->refresh()->legal_name)->toBeNull()
        ->and(Activity::query()->where('event', 'property.updated')->count())->toBe(0);
});

test('the database refuses a blank issuer or number', function () {
    $property = Property::factory()->create();

    expect(fn () => PropertyLicence::factory()->for($property)->create(['issuer' => '  ']))->toThrow(QueryException::class);
});

test('staff without settings.manage cannot open or save the tab, and another property returns 404', function () {
    [$owner, $property] = businessProperty();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]);

    $this->actingAs($frontDesk)->get(route('settings.business', $property->code))->assertForbidden();
    $this->put(route('settings.business.update', $property->code), businessPayload())->assertForbidden();

    $other = Property::factory()->create();
    $this->actingAs($owner)->get(route('settings.business', $other->code))->assertNotFound();
});

test('the profile tab no longer saves the registration number or TIN', function () {
    [$owner, $property] = businessProperty();
    $property->update(['address_line1' => 'Lot 12', 'city' => 'Kota Kinabalu', 'registration_number' => '202301012345']);

    $this->actingAs($owner)
        ->put(route('settings.profile.update', $property->code), [
            'name' => $property->name,
            'address_line1' => 'Lot 12',
            'city' => 'Kota Kinabalu',
            'registration_number' => 'CHANGED',
            'tin' => 'CHANGED',
        ])
        ->assertSessionHasNoErrors();

    expect($property->refresh()->registration_number)->toBe('202301012345')
        ->and($property->tin)->toBeNull();
});
