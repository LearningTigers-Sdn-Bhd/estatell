<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Registration\ApplyTermsPreset;
use App\Actions\Registration\CreateTermsDocument;
use App\Actions\Registration\DeleteTermsDocument;
use App\Actions\Registration\PublishRegistrationTerms;
use App\Actions\Registration\SeedRegistrationTerms;
use App\Enums\DefaultRoleEnum;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Enums\PropertyType;
use App\Enums\TermsPlaceholder;
use App\Enums\TermsPreset;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RoomType;
use App\Models\TermsDocument;
use App\Models\User;
use App\Services\Registration\TermsRenderer;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: TermsDocument} */
function termsProperty(PropertyType $type = PropertyType::Hotel): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    app(SeedRegistrationTerms::class)($property, $type);

    return [$owner, $property, $property->termsDocuments()->where('is_default', true)->firstOrFail()];
}

function termsUrl(Property $property, string $name = 'settings.registration-card.terms', array $parameters = []): string
{
    return route($name, [$property->code, ...$parameters]);
}

test('every preset ships in both languages with a version, 3 to 5 checklist items and only known placeholders', function (TermsPreset $preset, Locale $locale) {
    $renderer = app(TermsRenderer::class);
    $body = $preset->body($locale);

    expect($preset->version($locale))->toBeGreaterThanOrEqual(1)
        ->and(count($renderer->items($body)))->toBeBetween(3, 5)
        ->and($renderer->unknownPlaceholders($body))->toBe([])
        ->and($body)->not->toContain('version:');
})->with(fn () => collect(TermsPreset::cases())->crossJoin(Locale::cases())->all());

test('the renderer fills placeholders, lists the checklist items and leaves them out of the html', function () {
    $renderer = app(TermsRenderer::class);
    $body = "# Terms\n\nWelcome to {{property_name}}.\n\n- [ ] I leave by {{check_out_time}}.\n- [ ] I accept the {{ property_name }} notice.\n";

    $result = $renderer->render($body, ['property_name' => 'Sea House', 'check_out_time' => '11:00 AM']);

    expect($result['items'])->toBe([
        ['position' => 0, 'text' => 'I leave by 11:00 AM.'],
        ['position' => 1, 'text' => 'I accept the Sea House notice.'],
    ])
        ->and($result['html'])->toContain('Welcome to Sea House.')
        ->and($result['html'])->not->toContain('I leave by')
        ->and($renderer->unknownPlaceholders('{{property_name}} {{room_rate}} {{room_rate}}'))->toBe(['room_rate']);
});

test('the renderer numbers checklist items across several sections in text order', function () {
    $body = "## Stay\n\n- [ ] First.\n\nSome text.\n\n## Privacy\n\n- [ ] Second.\n- [ ] Third.\n";

    expect(app(TermsRenderer::class)->render($body, [])['items'])->toBe([
        ['position' => 0, 'text' => 'First.'],
        ['position' => 1, 'text' => 'Second.'],
        ['position' => 2, 'text' => 'Third.'],
    ]);
});

test('the renderer strips raw html', function () {
    $result = app(TermsRenderer::class)->render("Safe text\n\n<script>alert(1)</script>", []);

    expect($result['html'])->not->toContain('<script>')->and($result['html'])->toContain('Safe text');
});

test('placeholder values come from the property, with empty tourism tax text when none is set', function () {
    [, $property] = termsProperty();
    $property->settings->update(['check_in_time' => '14:30', 'check_out_time' => '12:00', 'security_deposit_amount' => '50.00']);
    $renderer = app(TermsRenderer::class);

    $without = $renderer->valuesFor($property->refresh());

    expect($without)->toHaveKeys(array_column(TermsPlaceholder::cases(), 'value'))
        ->and($without['property_name'])->toBe($property->name)
        ->and($without['check_in_time'])->toBe('2:30 PM')
        ->and($without['check_out_time'])->toBe('12:00 PM')
        ->and($without['security_deposit'])->toBe('MYR 50.00')
        ->and($without['tourism_tax_rate'])->toBe('');

});

test('the tourism tax placeholder reads the enabled tourism tax', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');

    $tourismTax = fn () => $property->taxes()->whereHas('transactionCode', fn ($query) => $query->where('system_key', 'tourism_tax'));

    expect(app(TermsRenderer::class)->valuesFor($property->refresh())['tourism_tax_rate'])->toBe('');

    $tourismTax()->update(['enabled' => true]);

    expect(app(TermsRenderer::class)->valuesFor($property->refresh())['tourism_tax_rate'])->toBe('MYR 10.00 per night');

    $tourismTax()->update(['rate_type' => 'percent', 'rate' => '6.00']);

    expect(app(TermsRenderer::class)->valuesFor($property->refresh())['tourism_tax_rate'])->toBe('6%');

    $tourismTax()->update(['enabled' => false]);

    expect(app(TermsRenderer::class)->valuesFor($property->refresh())['tourism_tax_rate'])->toBe('');
});

test('property defaults seed one default terms document with version 1 in both languages', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);

    app(SeedPropertyDefaults::class)($property, PropertyType::Hostel, 'MY');
    app(SeedRegistrationTerms::class)($property, PropertyType::Hostel);

    $document = $property->termsDocuments()->sole();
    $versions = $document->versions()->orderBy('locale')->get();

    expect($document->name)->toBe('Standard')
        ->and($document->is_default)->toBeTrue()
        ->and($versions)->toHaveCount(2)
        ->and($versions->pluck('locale')->all())->toBe([Locale::English, Locale::Malay])
        ->and($versions->pluck('version')->unique()->all())->toBe([1])
        ->and($versions->pluck('preset')->unique()->all())->toBe([TermsPreset::Hostel])
        ->and($versions->first()->body)->toBe(TermsPreset::Hostel->body(Locale::English));
});

test('a property without a type gets no document', function () {
    $property = Property::factory()->create(['type' => null]);

    app(SeedRegistrationTerms::class)($property);

    expect($property->termsDocuments()->count())->toBe(0);
});

test('publishing makes the next version per language and refuses unchanged text', function () {
    [$owner, $property, $document] = termsProperty();
    $publish = app(PublishRegistrationTerms::class);

    $second = $publish($document, Locale::English, $owner, "New text\r\n");

    expect($second->version)->toBe(2)
        ->and($second->body)->toBe('New text')
        ->and($second->preset)->toBeNull()
        ->and($publish($document, Locale::English, $owner, 'New text'))->toBeNull()
        ->and($document->versions()->forLocale(Locale::English)->count())->toBe(2)
        ->and($document->versions()->forLocale(Locale::Malay)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'registration_terms.published')->count())->toBe(1);
});

test('applying a template adds a version, and is refused when the text is already there', function () {
    [$owner, $property, $document] = termsProperty(PropertyType::Resort);
    $property->update(['type' => PropertyType::Resort]);
    $document->versions()->forLocale(Locale::English)->update(['body' => 'Old text', 'preset_version' => 0]);
    $apply = app(ApplyTermsPreset::class);

    $applied = $apply($document, Locale::English, $owner);

    expect($applied->version)->toBe(2)
        ->and($applied->preset)->toBe(TermsPreset::Resort)
        ->and($applied->preset_version)->toBe(TermsPreset::Resort->version(Locale::English))
        ->and($apply($document, Locale::English, $owner))->toBeNull()
        ->and($document->versions()->forLocale(Locale::English)->count())->toBe(2);
});

test('a document is deleted only when it is not the default and no room type uses it', function () {
    [$owner, $property, $default] = termsProperty();
    $extra = app(CreateTermsDocument::class)($property, $owner, 'Long stay');
    $used = app(CreateTermsDocument::class)($property, $owner, 'Dorm');
    RoomType::factory()->for($property)->create(['terms_document_id' => $used->id]);
    $delete = app(DeleteTermsDocument::class);

    expect($delete($default, $owner))->toBeFalse()
        ->and($delete($used, $owner))->toBeFalse()
        ->and($delete($extra, $owner))->toBeTrue()
        ->and($property->termsDocuments()->count())->toBe(2)
        ->and($extra->versions()->count())->toBe(0);
});

test('a new document starts as a copy of the chosen template, or the property type template by default', function () {
    [$owner, $property] = termsProperty();

    $hostel = app(CreateTermsDocument::class)($property, $owner, 'Dorm', TermsPreset::Hostel);
    $default = app(CreateTermsDocument::class)($property, $owner, 'Other');

    expect($hostel->versions()->forLocale(Locale::English)->sole()->preset)->toBe(TermsPreset::Hostel)
        ->and($default->versions()->forLocale(Locale::English)->sole()->preset)->toBe(TermsPreset::StandardHotel);
});

test('a new document starts as a copy of the template in both languages', function () {
    [$owner, $property] = termsProperty();

    $document = app(CreateTermsDocument::class)($property, $owner, 'Long stay');

    expect($document->is_default)->toBeFalse()
        ->and($document->versions()->count())->toBe(2);
});

test('the terms tab sends the documents and the editor for the open language', function () {
    [$owner, $property, $document] = termsProperty();

    $this->actingAs($owner)
        ->get(termsUrl($property))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/registration-card/edit')
            ->where('tab', 'terms')
            ->has('termsPresets', count(TermsPreset::cases()))
            ->where('defaultTermsPreset', 'standard_hotel')
            ->has('termsDocuments', 1)
            ->where('termsDocuments.0.isDefault', true)
            ->where('termsEditor.documentId', $document->id)
            ->where('termsEditor.locale', 'en')
            ->where('termsEditor.version', 1)
            ->where('termsEditor.isDraft', false)
            ->where('termsEditor.newerTemplateBody', null)
            ->where('termsEditor.localeOptions.1.label', 'Bahasa Malaysia')
            ->has('termsEditor.history', 1)
            ->has('termsEditor.placeholderValues.property_name'));
});

test('a language with no version opens the template as an unpublished draft', function () {
    [$owner, $property, $document] = termsProperty();
    $document->versions()->forLocale(Locale::Malay)->delete();

    $this->actingAs($owner)
        ->get(termsUrl($property, parameters: ['locale' => 'ms', 'document' => $document->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('termsEditor.locale', 'ms')
            ->where('termsEditor.isDraft', true)
            ->where('termsEditor.version', null)
            ->where('termsEditor.localeOptions.1.label', 'Bahasa Malaysia (not published)')
            ->where('termsEditor.body', TermsPreset::StandardHotel->body(Locale::Malay)));
});

test('the terms tab offers a newer template when the template version moved on', function () {
    [$owner, $property, $document] = termsProperty();
    $document->versions()->forLocale(Locale::English)->update(['preset_version' => 0]);

    $this->actingAs($owner)
        ->get(termsUrl($property))
        ->assertInertia(fn (Assert $page) => $page->where('termsEditor.newerTemplateBody', TermsPreset::StandardHotel->body(Locale::English)));
});

test('a document of another property is not found', function () {
    [$owner, $property] = termsProperty();
    [, , $foreign] = termsProperty();

    $this->actingAs($owner)
        ->post(termsUrl($property, 'settings.registration-card.terms.versions.store', [$foreign->id]), ['locale' => 'en', 'body' => 'x'])
        ->assertNotFound();
    $this->delete(termsUrl($property, 'settings.registration-card.terms.documents.destroy', [$foreign->id]))->assertNotFound();
});

test('publishing through the page redirects back to the open document and language', function () {
    [$owner, $property, $document] = termsProperty();

    $this->actingAs($owner)
        ->post(termsUrl($property, 'settings.registration-card.terms.versions.store', [$document->id]), ['locale' => 'ms', 'body' => "Terma baharu\n\n- [ ] Saya setuju."])
        ->assertRedirect(route('settings.registration-card.terms', [$property->code, 'document' => $document->id, 'locale' => 'ms']));

    expect($document->versions()->forLocale(Locale::Malay)->max('version'))->toBe(2);
});

test('publishing rejects unknown placeholders, too many items and an empty text', function () {
    [$owner, $property, $document] = termsProperty();
    $url = termsUrl($property, 'settings.registration-card.terms.versions.store', [$document->id]);
    $eleven = implode("\n", array_map(fn (int $number): string => "- [ ] Item {$number}", range(1, 11)));

    $this->actingAs($owner)->post($url, ['locale' => 'en', 'body' => 'Rate {{room_rate}}'])->assertSessionHasErrors('body');
    $this->post($url, ['locale' => 'en', 'body' => $eleven])->assertSessionHasErrors('body');
    $this->post($url, ['locale' => 'en', 'body' => ''])->assertSessionHasErrors('body');
    $this->post($url, ['locale' => 'fr', 'body' => 'x'])->assertSessionHasErrors('locale');

    expect($document->versions()->forLocale(Locale::English)->count())->toBe(1);
});

test('publishing the same text shows an error toast and writes nothing', function () {
    [$owner, $property, $document] = termsProperty();
    $body = $document->versions()->forLocale(Locale::English)->value('body');

    $this->actingAs($owner)
        ->post(termsUrl($property, 'settings.registration-card.terms.versions.store', [$document->id]), ['locale' => 'en', 'body' => $body])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect($document->versions()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'registration_terms.published')->count())->toBe(0);
});

test('adding a document needs a name the property does not use yet', function () {
    [$owner, $property] = termsProperty();
    $url = termsUrl($property, 'settings.registration-card.terms.documents.store');

    $this->actingAs($owner)->post($url, ['name' => 'standard', 'preset' => 'hostel'])->assertSessionHasErrors('name');
    $this->post($url, ['name' => '', 'preset' => 'hostel'])->assertSessionHasErrors('name');
    $this->post($url, ['name' => 'Long stay', 'preset' => 'hostel'])->assertRedirect();
    $this->post($url, ['name' => 'No template'])->assertSessionHasErrors('preset');

    expect($property->termsDocuments()->count())->toBe(2);
});

test('deleting the default document shows an error toast', function () {
    [$owner, $property, $document] = termsProperty();

    $this->actingAs($owner)
        ->delete(termsUrl($property, 'settings.registration-card.terms.documents.destroy', [$document->id]))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect($property->termsDocuments()->count())->toBe(1);
});

test('the template can be applied through the page', function () {
    [$owner, $property, $document] = termsProperty();
    $document->versions()->forLocale(Locale::English)->update(['body' => 'Old text']);

    $this->actingAs($owner)
        ->post(termsUrl($property, 'settings.registration-card.terms.preset.apply', [$document->id]), ['locale' => 'en'])
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect($document->versions()->forLocale(Locale::English)->max('version'))->toBe(2);
});

test('only the Owner and Manager roles manage terms', function (DefaultRoleEnum $role, bool $allowed) {
    $user = User::factory()->withRole($role)->create();
    PropertySetting::factory()->for($user->property)->create();
    app(SeedRegistrationTerms::class)($user->property, PropertyType::Hotel);

    $response = $this->actingAs($user)->get(termsUrl($user->property));

    $allowed ? $response->assertOk() : $response->assertForbidden();
    expect(DefaultRoleEnum::from($role->value)->permissions())->when($allowed, fn ($permissions) => $permissions->toContain(PermissionEnum::RegistrationCardManageTerms));
})->with([
    [DefaultRoleEnum::Owner, true],
    [DefaultRoleEnum::Manager, true],
    [DefaultRoleEnum::FrontDesk, false],
    [DefaultRoleEnum::Finance, false],
]);

test('the card tabs tell the page whether the user may manage terms', function () {
    [$owner, $property] = termsProperty();

    $this->actingAs($owner)
        ->get(route('settings.registration-card.general', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('canManageTerms', true));
});

test('a room type can use a terms document of its own property only', function () {
    [$owner, $property, $document] = termsProperty();
    [, , $foreign] = termsProperty();
    $roomType = RoomType::factory()->for($property)->create();
    $payload = fn (?int $documentId): array => [
        'name' => $roomType->name, 'sell_unit' => $roomType->sell_unit->value, 'max_adults' => 2, 'max_children' => 0,
        'base_occupancy' => 2, 'terms_document_id' => $documentId,
    ];
    $extra = app(CreateTermsDocument::class)($property, $owner, 'Long stay');
    $url = route('settings.room-types.update', [$property->code, $roomType->id]);

    $this->actingAs($owner)->put($url, $payload($foreign->id))->assertSessionHasErrors('terms_document_id');
    $this->put($url, $payload($extra->id))->assertSessionHasNoErrors();
    expect($roomType->refresh()->terms_document_id)->toBe($extra->id);

    $this->put($url, $payload(null))->assertSessionHasNoErrors();
    expect($roomType->refresh()->terms_document_id)->toBeNull();
});

test('the room types page lists documents only when there is a second one', function () {
    [$owner, $property] = termsProperty();

    $this->actingAs($owner)
        ->get(route('settings.room-types.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('termsDocuments', []));

    app(CreateTermsDocument::class)($property, $owner, 'Long stay');

    $this->get(route('settings.room-types.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->has('termsDocuments', 2));
});

test('deleting a document used by a room type falls back to null at the database', function () {
    [$owner, $property] = termsProperty();
    $extra = app(CreateTermsDocument::class)($property, $owner, 'Long stay');
    $roomType = RoomType::factory()->for($property)->create(['terms_document_id' => $extra->id]);

    $extra->delete();

    expect($roomType->refresh()->terms_document_id)->toBeNull();
});
