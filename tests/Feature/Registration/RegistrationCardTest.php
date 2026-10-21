<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Registration\SaveRegistrationCardFields;
use App\Actions\Registration\SeedRegistrationCardFields;
use App\Enums\DefaultRoleEnum;
use App\Enums\FieldVisibility;
use App\Enums\PropertyType;
use App\Enums\RegistrationCardField;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property} */
function registrationCardProperty(): array
{
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();
    app(SeedRegistrationCardFields::class)($owner->property);

    return [$owner, $owner->property];
}

/** @return array<string, string> */
function registrationCardInput(Property $property, array $changes = []): array
{
    return [
        ...$property->registrationCardFields->mapWithKeys(fn ($setting): array => [$setting->field->value => $setting->visibility->value])->all(),
        ...$changes,
    ];
}

/** @return list<string> */
function registrationCardOrder(Property $property): array
{
    return $property->registrationCardFields()->ordered()->get()->map(fn ($setting): string => $setting->field->value)->all();
}

/** @return array{fields: array<string, string>, order: list<string>} */
function registrationCardPayload(Property $property, array $changes = [], ?array $order = null): array
{
    return [
        'fields' => registrationCardInput($property->refresh()->load('registrationCardFields'), $changes),
        'order' => $order ?? registrationCardOrder($property),
    ];
}

test('seeding creates every field with its default and keeps edits', function () {
    $property = Property::factory()->create();

    app(SeedRegistrationCardFields::class)($property);
    $property->registrationCardFields()->where('field', 'gender')->update(['visibility' => 'required']);
    $property->registrationCardFields()->where('field', 'phone')->delete();
    app(SeedRegistrationCardFields::class)($property);

    $rows = $property->registrationCardFields()->ordered()->get();

    expect($rows)->toHaveCount(count(RegistrationCardField::cases()))
        ->and($rows->pluck('field')->all())->toBe(RegistrationCardField::cases())
        ->and($rows->firstWhere('field', RegistrationCardField::Gender)->visibility)->toBe(FieldVisibility::Required)
        ->and($rows->firstWhere('field', RegistrationCardField::Phone)->visibility)->toBe(FieldVisibility::Required)
        ->and($rows->firstWhere('field', RegistrationCardField::RatePerNight)->visibility)->toBe(FieldVisibility::Hidden)
        ->and($rows->firstWhere('field', RegistrationCardField::Email)->visibility)->toBe(FieldVisibility::Optional);
});

test('property defaults seed the registration card fields', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);

    app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');

    expect($property->registrationCardFields()->count())->toBe(count(RegistrationCardField::cases()));
});

test('the layout tab lists the fields in card order', function () {
    [$owner, $property] = registrationCardProperty();
    Property::factory()->create()->registrationCardFields()->create(['field' => 'phone', 'visibility' => 'hidden', 'position' => 6]);

    $this->actingAs($owner)
        ->get(route('settings.registration-card.layout', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/registration-card/edit')
            ->where('tab', 'layout')
            ->has('fields', count(RegistrationCardField::cases()))
            ->where('fields.0.field', 'full_name')
            ->where('fields.0.canHide', false)
            ->where('fields.1.field', 'nationality')
            ->where('fields.1.canHide', true)
            ->where('fields.1.canRequire', true)
            ->where('fields.9.field', 'remarks')
            ->where('fields.9.canRequire', true)
            ->where('fields.10.field', 'marketing_consent')
            ->where('fields.10.canRequire', false)
            ->where('fields.11.displayOnly', true)
            ->where('propertyName', $property->name)
            ->missing('registrationCardScopes'));
});

test('the general tab opens first and sends only its options', function () {
    [$owner, $property] = registrationCardProperty();

    $this->actingAs($owner)
        ->get(route('settings.registration-card.general', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/registration-card/edit')
            ->where('tab', 'general')
            ->has('registrationCardScopes')
            ->missing('fields'));
});

test('a property without settings is sent to the details page', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('settings.registration-card.general', $owner->property->code))
        ->assertRedirect(route('settings.edit', $owner->property->code));
    $this->get(route('settings.registration-card.layout', $owner->property->code))
        ->assertRedirect(route('settings.edit', $owner->property->code));
});

test('saving changes the visibilities and logs the difference', function () {
    [$owner, $property] = registrationCardProperty();

    $this->actingAs($owner)
        ->put(route('settings.registration-card.fields.update', $property->code), [
            ...registrationCardPayload($property, ['gender' => 'optional', 'total_charges' => 'required', 'email' => 'hidden']),
        ])
        ->assertRedirect(route('settings.registration-card.layout', $property->code))
        ->assertSessionHasNoErrors();

    $fields = $property->registrationCardFields()->get()->keyBy(fn ($setting) => $setting->field->value);

    expect($fields['gender']->visibility)->toBe(FieldVisibility::Optional)
        ->and($fields['total_charges']->visibility)->toBe(FieldVisibility::Required)
        ->and($fields['email']->visibility)->toBe(FieldVisibility::Hidden)
        ->and($fields['phone']->visibility)->toBe(FieldVisibility::Required);

    $log = Activity::query()->where('event', 'registration_card_fields.updated')->sole();
    expect($log->properties['after'])->toBe(['gender' => 'optional', 'email' => 'hidden', 'total_charges' => 'required']);
});

test('a save that changes nothing is not logged', function () {
    [$owner, $property] = registrationCardProperty();

    expect(app(SaveRegistrationCardFields::class)(
        $property,
        $owner,
        array_map(fn (string $value): FieldVisibility => FieldVisibility::from($value), registrationCardInput($property)),
        RegistrationCardField::cases(),
    ))->toBeTrue()
        ->and(Activity::query()->where('event', 'registration_card_fields.updated')->count())->toBe(0);
});

test('values a field type does not allow are rejected', function (string $field, string $value) {
    [$owner, $property] = registrationCardProperty();

    $this->actingAs($owner)
        ->put(route('settings.registration-card.fields.update', $property->code), [
            ...registrationCardPayload($property, [$field => $value]),
        ])
        ->assertSessionHasErrors("fields.{$field}");

    expect(Activity::query()->where('event', 'registration_card_fields.updated')->count())->toBe(0);
})->with([
    'full name hidden' => ['full_name', 'hidden'],
    'full name optional' => ['full_name', 'optional'],
    'marketing consent required' => ['marketing_consent', 'required'],
    'a display-only field optional' => ['room', 'optional'],
    'an unknown value' => ['phone', 'maybe'],
]);

test('every field must be sent', function () {
    [$owner, $property] = registrationCardProperty();
    $payload = registrationCardPayload($property);
    unset($payload['fields']['phone']);

    $this->actingAs($owner)
        ->put(route('settings.registration-card.fields.update', $property->code), $payload)
        ->assertSessionHasErrors('fields.phone');
});

test('nationality cannot be hidden while a tax for foreign guests only is enabled', function () {
    [$owner, $property] = registrationCardProperty();
    $tax = Tax::factory()->for($property)->create(['foreign_guests_only' => true, 'enabled' => true]);

    $this->actingAs($owner)
        ->get(route('settings.registration-card.layout', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fields.1.field', 'nationality')
            ->where('fields.1.canHide', false)
            ->where('fields.1.note', fn ($note) => $note !== null));

    $this->put(route('settings.registration-card.fields.update', $property->code), registrationCardPayload($property, ['nationality' => 'hidden']))
        ->assertSessionHasNoErrors();

    expect($property->registrationCardFields()->where('field', 'nationality')->value('visibility'))->toBe(FieldVisibility::Required)
        ->and(Activity::query()->where('event', 'registration_card_fields.updated')->count())->toBe(0);

    $tax->update(['enabled' => false]);

    $this->put(route('settings.registration-card.fields.update', $property->code), registrationCardPayload($property, ['nationality' => 'hidden']))
        ->assertSessionHasNoErrors();

    expect($property->registrationCardFields()->where('field', 'nationality')->value('visibility'))->toBe(FieldVisibility::Hidden);
});

test('the general tab saves the card rules', function () {
    [$owner, $property] = registrationCardProperty();

    $this->actingAs($owner)
        ->put(route('settings.registration-card.update', $property->code), [
            'registration_card_scope' => 'every_adult', 'card_required_for_check_in' => 'on',
            'id_document_retention_days' => 30, 'security_deposit_amount' => '150.00',
        ])
        ->assertRedirect(route('settings.registration-card.general', $property->code))
        ->assertSessionHasNoErrors();

    $settings = $property->settings->refresh();

    expect($settings->registration_card_scope->value)->toBe('every_adult')
        ->and($settings->card_required_for_check_in)->toBeTrue()
        ->and($settings->id_document_retention_days)->toBe(30)
        ->and($settings->security_deposit_amount)->toBe('150.00');
});

test('the general tab lists the active charge items and saves the damage charge item, or None', function () {
    [$owner, $property] = registrationCardProperty();
    $item = ChargeItem::factory()->create(['property_id' => $property->id, 'name' => 'Damage']);
    ChargeItem::factory()->inactive()->create(['property_id' => $property->id]);
    $input = ['registration_card_scope' => 'primary_only', 'security_deposit_amount' => '100.00'];

    $this->actingAs($owner)
        ->get(route('settings.registration-card.general', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('chargeItems', 1)
            ->where('chargeItems.0.id', $item->id)
            ->where('settings.damageChargeItemId', null));

    $this->put(route('settings.registration-card.update', $property->code), [...$input, 'damage_charge_item_id' => $item->id])
        ->assertSessionHasNoErrors();
    expect($property->settings->refresh()->damage_charge_item_id)->toBe($item->id);

    $this->put(route('settings.registration-card.update', $property->code), [...$input, 'damage_charge_item_id' => ''])
        ->assertSessionHasNoErrors();
    expect($property->settings->refresh()->damage_charge_item_id)->toBeNull();
});

test('the damage charge item must be an active item of the property', function (Closure $item) {
    [$owner, $property] = registrationCardProperty();

    $this->actingAs($owner)
        ->put(route('settings.registration-card.update', $property->code), [
            'registration_card_scope' => 'primary_only', 'security_deposit_amount' => '100.00',
            'damage_charge_item_id' => $item($property)->id,
        ])
        ->assertSessionHasErrors('damage_charge_item_id');

    expect($property->settings->refresh()->damage_charge_item_id)->toBeNull();
})->with([
    'another property' => [fn (Property $property): ChargeItem => ChargeItem::factory()->create()],
    'inactive' => [fn (Property $property): ChargeItem => ChargeItem::factory()->inactive()->create(['property_id' => $property->id])],
]);

test('the registration card item is active on both tabs', function (string $routeName) {
    [$owner, $property] = registrationCardProperty();

    $this->actingAs($owner)
        ->get(route($routeName, $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.3.groups.0.items.0.active', false)
            ->where('navigation.contexts.3.groups.0.items.1', [
                'title' => 'Registration card',
                'href' => '/p/'.$property->code.'/settings/registration-card',
                'icon' => 'id-card',
                'external' => false,
                'active' => true,
                'action' => null,
                'badge' => null,
            ])
        );
})->with(['settings.registration-card.general', 'settings.registration-card.layout']);

test('routes enforce the settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.registration-card.general', $frontDesk->property->code))
        ->assertForbidden();
    $this->get(route('settings.registration-card.layout', $frontDesk->property->code))->assertForbidden();
    $this->put(route('settings.registration-card.update', $frontDesk->property->code), [])->assertForbidden();
    $this->put(route('settings.registration-card.fields.update', $frontDesk->property->code), [])->assertForbidden();
    $this->actingAs($owner)
        ->get(route('settings.registration-card.layout', $frontDesk->property->code))
        ->assertNotFound();
});

test('a field moves inside its group, and the layout follows the saved order', function () {
    [$owner, $property] = registrationCardProperty();
    $order = registrationCardOrder($property);
    [$order[1], $order[2]] = [$order[2], $order[1]];

    $this->actingAs($owner)
        ->put(route('settings.registration-card.fields.update', $property->code), registrationCardPayload($property, order: $order))
        ->assertSessionHasNoErrors();

    expect(registrationCardOrder($property))->toBe($order);

    $log = Activity::query()->where('event', 'registration_card_fields.updated')->sole();
    expect($log->properties['order']['after'])->toBe($order)
        ->and($log->properties['after'])->toBe([]);

    $this->get(route('settings.registration-card.layout', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fields.1.field', 'identity_document')
            ->where('fields.2.field', 'nationality'));
});

test('an order that leaves a group or repeats a field is refused', function (callable $reorder) {
    [$owner, $property] = registrationCardProperty();
    $original = registrationCardOrder($property);

    $this->actingAs($owner)
        ->put(route('settings.registration-card.fields.update', $property->code), registrationCardPayload($property, order: $reorder($original)));

    expect(registrationCardOrder($property))->toBe($original)
        ->and(Activity::query()->where('event', 'registration_card_fields.updated')->count())->toBe(0);
})->with([
    'a stay field before the guest fields' => [fn (array $order): array => [...array_slice($order, 11, 1), ...array_slice($order, 0, 11), ...array_slice($order, 12)]],
    'a repeated field' => [fn (array $order): array => [...array_slice($order, 0, 18), 'phone']],
    'a missing field' => [fn (array $order): array => array_slice($order, 0, 17)],
]);

test('a refused visibility leaves the order and every other row untouched', function () {
    [$owner, $property] = registrationCardProperty();
    Tax::factory()->for($property)->create(['foreign_guests_only' => true, 'enabled' => true]);
    $order = registrationCardOrder($property);
    [$order[3], $order[4]] = [$order[4], $order[3]];

    $this->actingAs($owner)
        ->put(route('settings.registration-card.fields.update', $property->code), registrationCardPayload($property, ['gender' => 'required', 'nationality' => 'hidden'], $order));

    expect($property->registrationCardFields()->where('field', 'gender')->value('visibility'))->toBe(FieldVisibility::Hidden)
        ->and(registrationCardOrder($property))->not->toBe($order);
});
