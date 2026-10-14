<?php

use App\Actions\Property\SaveAgeGroups;
use App\Actions\Property\SeedAgeGroups;
use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property} */
function seededAgeGroupProperty(): array
{
    $owner = User::factory()->owner()->create();
    app(SeedAgeGroups::class)($owner->property, 'MY');

    return [$owner, $owner->property];
}

/** @return list<array{label: string, min: int, max: int}> */
function ageGroupRows(Property $property): array
{
    return $property->ageGroups()->ordered()->get()
        ->map(fn (PropertyAgeGroup $group): array => ['label' => $group->label, 'min' => $group->min_age, 'max' => $group->max_age])
        ->all();
}

test('Malaysia properties receive Infant and Child idempotently', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);

    app(SeedAgeGroups::class)($property);
    $property->ageGroups()->where('label', 'Child')->update(['label' => 'Kid', 'max_age' => 9]);
    app(SeedAgeGroups::class)($property);

    expect(ageGroupRows($property))->toBe([
        ['label' => 'Infant', 'min' => 0, 'max' => 3],
        ['label' => 'Kid', 'min' => 4, 'max' => 9],
    ]);
});

test('the page lists this property\'s groups by minimum age and opens under the property settings navigation entry', function () {
    [$owner, $property] = seededAgeGroupProperty();
    PropertyAgeGroup::factory()->create(['label' => 'Other property']);

    $this->actingAs($owner)
        ->get(route('settings.age-groups', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/edit')
            ->where('tab', 'age-groups')
            ->has('ageGroups', 2)
            ->where('ageGroups.0.label', 'Infant')
            ->where('ageGroups.1.minAge', 4)
            ->where('ageGroups.1.maxAge', 11)
            ->where('maxAge', 17)
            ->where('navigation.contexts.3.groups.0.items.0.title', 'Details')
            ->where('navigation.contexts.3.groups.0.items.0.active', true));
});

test('saving renames and re-ranges groups and keeps their ids', function () {
    [$owner, $property] = seededAgeGroupProperty();
    [$infant, $child] = $property->ageGroups()->ordered()->get();

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => [
            ['id' => $infant->id, 'label' => ' Baby ', 'max_age' => 2],
            ['id' => $child->id, 'label' => 'Child', 'max_age' => 12],
        ]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Age groups saved.']);

    expect(ageGroupRows($property))->toBe([
        ['label' => 'Baby', 'min' => 0, 'max' => 2],
        ['label' => 'Child', 'min' => 3, 'max' => 12],
    ])
        ->and($infant->refresh()->label)->toBe('Baby')
        ->and($child->refresh()->min_age)->toBe(3)
        ->and(Activity::query()->where('event', 'age_groups.updated')->count())->toBe(1);
});

test('a new group starts after the last one and adults start after it', function () {
    [$owner, $property] = seededAgeGroupProperty();
    [$infant, $child] = $property->ageGroups()->ordered()->get();

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => [
            ['id' => $infant->id, 'label' => 'Infant', 'max_age' => 3],
            ['id' => $child->id, 'label' => 'Child', 'max_age' => 11],
            ['label' => 'Teen', 'max_age' => 15],
        ]])
        ->assertSessionHasNoErrors();

    expect(ageGroupRows($property))->toBe([
        ['label' => 'Infant', 'min' => 0, 'max' => 3],
        ['label' => 'Child', 'min' => 4, 'max' => 11],
        ['label' => 'Teen', 'min' => 12, 'max' => 15],
    ]);
});

test('removing a middle group lets the next one absorb its ages', function () {
    [$owner, $property] = seededAgeGroupProperty();
    [$infant, $child] = $property->ageGroups()->ordered()->get();

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => [
            ['id' => $child->id, 'label' => 'Child', 'max_age' => 11],
        ]])
        ->assertSessionHasNoErrors();

    expect(ageGroupRows($property))->toBe([['label' => 'Child', 'min' => 0, 'max' => 11]])
        ->and(PropertyAgeGroup::query()->whereKey($infant->id)->exists())->toBeFalse();
});

test('a property may have no age groups', function () {
    [$owner, $property] = seededAgeGroupProperty();

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => []])
        ->assertSessionHasNoErrors();

    expect($property->ageGroups()->count())->toBe(0);
});

test('labels can swap between rows in one save', function () {
    [$owner, $property] = seededAgeGroupProperty();
    [$infant, $child] = $property->ageGroups()->ordered()->get();

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => [
            ['id' => $infant->id, 'label' => 'Child', 'max_age' => 3],
            ['id' => $child->id, 'label' => 'Infant', 'max_age' => 11],
        ]])
        ->assertSessionHasNoErrors();

    expect(ageGroupRows($property))->toBe([
        ['label' => 'Child', 'min' => 0, 'max' => 3],
        ['label' => 'Infant', 'min' => 4, 'max' => 11],
    ]);
});

test('a save that changes nothing is not logged', function () {
    [$owner, $property] = seededAgeGroupProperty();
    [$infant, $child] = $property->ageGroups()->ordered()->get();

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => [
            ['id' => $infant->id, 'label' => 'Infant', 'max_age' => 3],
            ['id' => $child->id, 'label' => 'Child', 'max_age' => 11],
        ]])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'age_groups.updated')->count())->toBe(0);
});

test('saved groups are validated', function (Closure $groups, string $field) {
    [$owner, $property] = seededAgeGroupProperty();
    [$infant, $child] = $property->ageGroups()->ordered()->get();
    $foreign = PropertyAgeGroup::factory()->create();

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => $groups($infant->id, $child->id, $foreign->id)])
        ->assertSessionHasErrors($field);

    expect(ageGroupRows($property))->toBe([
        ['label' => 'Infant', 'min' => 0, 'max' => 3],
        ['label' => 'Child', 'min' => 4, 'max' => 11],
    ]);
})->with([
    'label is required' => [fn ($a, $b) => [['id' => $a, 'label' => '', 'max_age' => 3]], 'groups.0.label'],
    'label is unique ignoring case' => [fn ($a, $b) => [['id' => $a, 'label' => 'Kid', 'max_age' => 3], ['id' => $b, 'label' => 'kID', 'max_age' => 9]], 'groups.1.label'],
    'label is at most 50 characters' => [fn ($a, $b) => [['id' => $a, 'label' => str_repeat('a', 51), 'max_age' => 3]], 'groups.0.label'],
    'age is required' => [fn ($a, $b) => [['id' => $a, 'label' => 'Infant']], 'groups.0.max_age'],
    'age is a whole number' => [fn ($a, $b) => [['id' => $a, 'label' => 'Infant', 'max_age' => '2.5']], 'groups.0.max_age'],
    'age is not negative' => [fn ($a, $b) => [['id' => $a, 'label' => 'Infant', 'max_age' => -1]], 'groups.0.max_age'],
    'age is at most 17' => [fn ($a, $b) => [['id' => $a, 'label' => 'Infant', 'max_age' => 18]], 'groups.0.max_age'],
    'age is higher than the group above' => [fn ($a, $b) => [['id' => $a, 'label' => 'Infant', 'max_age' => 5], ['id' => $b, 'label' => 'Child', 'max_age' => 5]], 'groups.1.max_age'],
    'id belongs to this property' => [fn ($a, $b, $foreign) => [['id' => $foreign, 'label' => 'Infant', 'max_age' => 3]], 'groups.0.id'],
    'ids are not repeated' => [fn ($a, $b) => [['id' => $a, 'label' => 'Infant', 'max_age' => 3], ['id' => $a, 'label' => 'Child', 'max_age' => 9]], 'groups.0.id'],
]);

test('the action refuses an id that is no longer one of the property groups', function () {
    [$owner, $property] = seededAgeGroupProperty();
    $foreign = PropertyAgeGroup::factory()->create();

    expect(app(SaveAgeGroups::class)($property, $owner, [['id' => $foreign->id, 'label' => 'Infant', 'max_age' => 3]]))->toBeFalse()
        ->and($foreign->refresh()->property_id)->not->toBe($property->id)
        ->and(Activity::query()->where('event', 'age_groups.updated')->count())->toBe(0);
});

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.age-groups', $frontDesk->property->code))
        ->assertForbidden();
    $this->put(route('settings.age-groups.update', $frontDesk->property->code), ['groups' => []])
        ->assertForbidden();
    $this->actingAs($owner)
        ->get(route('settings.age-groups', 'no-such-property'))
        ->assertNotFound();
});

test('the database enforces age group invariants', function (array $attributes) {
    $property = Property::factory()->create();
    PropertyAgeGroup::factory()->for($property)->create(['label' => 'Infant', 'min_age' => 0, 'max_age' => 3]);

    DB::table('property_age_groups')->insert([
        'property_id' => $property->id,
        'label' => 'Child',
        'min_age' => 4,
        'max_age' => 11,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);

    // The minimum-age constraint is deferred to commit, so check it now.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
})->throws(QueryException::class)->with([
    'maximum is not below minimum' => [['min_age' => 6, 'max_age' => 5]],
    'maximum is at most 17' => [['max_age' => 18]],
    'minimum is unique per property' => [['min_age' => 0]],
    'label is unique ignoring case' => [['label' => 'iNfAnT']],
    'label is trimmed' => [['label' => ' Child ']],
    'label is not empty' => [['label' => '']],
]);

test('a new group gets Percent 100 on per guest charge items and flags them for review', function () {
    [$owner, $property] = seededAgeGroupProperty();
    [$infant, $child] = $property->ageGroups()->ordered()->get();
    $priced = ChargeItem::factory()->for($property)->create(['charging_unit' => ChargingUnit::PerGuest, 'default_amount' => '30.00']);
    $open = ChargeItem::factory()->for($property)->create(['charging_unit' => ChargingUnit::PerGuestNight, 'default_amount' => null]);
    $perItem = ChargeItem::factory()->for($property)->create(['charging_unit' => ChargingUnit::PerItem]);

    $this->actingAs($owner)
        ->put(route('settings.age-groups.update', $property->code), ['groups' => [
            ['id' => $infant->id, 'label' => 'Infant', 'max_age' => 3],
            ['id' => $child->id, 'label' => 'Child', 'max_age' => 11],
            ['label' => 'Teen', 'max_age' => 15],
        ]])
        ->assertSessionHasNoErrors();

    $teen = $property->ageGroups()->where('label', 'Teen')->sole();

    expect($priced->childPrices()->where('property_age_group_id', $teen->id)->sole()->only(['pricing', 'value']))->toBe(['pricing' => AgeBandPricing::Percent, 'value' => '100.00'])
        ->and($open->childPrices()->where('property_age_group_id', $teen->id)->sole()->pricing)->toBe(AgeBandPricing::Free)
        ->and($priced->refresh()->needs_child_price_review)->toBeTrue()
        ->and($open->refresh()->needs_child_price_review)->toBeTrue()
        ->and($perItem->childPrices()->count())->toBe(0)
        ->and($perItem->refresh()->needs_child_price_review)->toBeFalse();
});
