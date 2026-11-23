<?php

use App\Enums\FacilitySharing;
use App\Enums\PermissionEnum;
use App\Enums\TransactionCategory;
use App\Features\Facilities;
use App\Models\ChargeItem;
use App\Models\Facility;
use App\Models\FacilitySession;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * An owner of a property with the Facilities feature on.
 *
 * @return array{owner: User, property: Property}
 */
function facilitySetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    Feature::for($property)->activate(Facilities::class);

    return ['owner' => $owner, 'property' => $property];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function facilityPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Riverside Hall',
        'sharing' => 'private',
        'capacity' => '150',
        'capacity_unit' => 'pax',
        'charge_by_hand' => false,
        'default_amount' => '1500',
        'rate_basis' => 'day',
    ], $overrides);
}

test('owners add a facility with its own Facility charge item at the end of the list', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    Facility::factory()->for($property)->create(['position' => 4]);

    $this->actingAs($owner)->post(route('settings.facilities.store', $property->code), facilityPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.facilities.index', $property->code));

    $hall = Facility::query()->where('name', 'Riverside Hall')->sole();
    $chargeItem = $hall->chargeItem()->with('transactionCode')->sole();

    expect($hall->sharing)->toBe(FacilitySharing::Private)
        ->and($hall->capacity)->toBe(150)
        ->and($hall->position)->toBe(5)
        ->and($chargeItem->name)->toBe('Riverside Hall')
        ->and($chargeItem->default_amount)->toBe('1500.00')
        ->and($chargeItem->auto_add)->toBeFalse()
        ->and($chargeItem->transactionCode->code)->toBe('RIVERSIDE_HALL')
        ->and($chargeItem->transactionCode->category)->toBe(TransactionCategory::Facility);
    expect(Activity::query()->where('event', 'facility.created')->count())->toBe(1);

    $this->get(route('settings.facilities.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/facilities/index')
            ->has('facilities', 2)
            ->where('facilities.1.name', 'Riverside Hall')
            ->where('facilities.1.amount', '1500.00'));
});

test('charge by hand clears the price of the same charge item and keeps its code and taxes', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $hall = Facility::factory()->for($property)->create(['name' => 'Riverside Hall']);
    $tax = Tax::factory()->for($property)->create();
    $hall->chargeItem->transactionCode->applicableTaxes()->sync([$tax->id]);
    $code = $hall->chargeItem->transactionCode->code;

    $this->actingAs($owner)
        ->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['name' => 'River Hall', 'capacity' => '100', 'charge_by_hand' => true, 'default_amount' => '900']))
        ->assertSessionHasNoErrors();

    $chargeItem = ChargeItem::query()->with('transactionCode.applicableTaxes')->findOrFail($hall->charge_item_id);
    expect($hall->refresh()->name)->toBe('River Hall')
        ->and($chargeItem->name)->toBe('River Hall')
        ->and($chargeItem->default_amount)->toBeNull()
        ->and($chargeItem->transactionCode->code)->toBe($code)
        ->and($chargeItem->transactionCode->applicableTaxes->modelKeys())->toBe([$tax->id]);
    expect(Activity::query()->where('event', 'facility.updated')->sole()->properties['changed'])
        ->toEqualCanonicalizing(['name', 'default_amount']);
});

test('turning a facility off turns its charge item off, and a save without Active keeps the state', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $hall = Facility::factory()->for($property)->create(['name' => 'Riverside Hall']);

    $this->actingAs($owner)
        ->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['active' => '0']))
        ->assertSessionHasNoErrors();

    expect($hall->refresh()->active)->toBeFalse()
        ->and($hall->chargeItem->refresh()->active)->toBeFalse();

    $this->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['capacity' => '120']));

    expect($hall->refresh()->active)->toBeFalse()->and($hall->capacity)->toBe(120);
});

test('a facility needs a free name and a rate unless it is charged by hand', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    Facility::factory()->for($property)->create(['name' => 'BBQ pit']);
    ChargeItem::factory()->for($property)->create(['name' => 'Kayak']);
    $this->actingAs($owner);

    $this->post(route('settings.facilities.store', $property->code), facilityPayload(['name' => 'bbq PIT']))
        ->assertSessionHasErrors(['name' => 'Another facility already uses this name.']);
    $this->post(route('settings.facilities.store', $property->code), facilityPayload(['name' => 'kayak']))
        ->assertSessionHasErrors(['name' => 'A charge item already uses this name. Choose another name.']);
    $this->post(route('settings.facilities.store', $property->code), facilityPayload(['default_amount' => '']))
        ->assertSessionHasErrors(['default_amount' => 'Enter a rate, or turn on Charge by hand.']);
    $this->post(route('settings.facilities.store', $property->code), facilityPayload(['capacity' => '0', 'sharing' => 'whole']))
        ->assertSessionHasErrors(['capacity', 'sharing']);

    $this->post(route('settings.facilities.store', $property->code), facilityPayload(['default_amount' => '', 'charge_by_hand' => true]))
        ->assertSessionHasNoErrors();
    expect(Facility::query()->where('name', 'Riverside Hall')->sole()->chargeItem->default_amount)->toBeNull();
});

test('reorder saves the order of all facilities and refuses a stale list', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    [$first, $second, $third] = Facility::factory()->for($property)->count(3)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $third->update(['active' => false]);
    $this->actingAs($owner);

    $this->put(route('settings.facilities.reorder', $property->code), ['order' => [$third->id, $first->id, $second->id]])
        ->assertSessionHasNoErrors();
    expect([$third->refresh()->position, $first->refresh()->position, $second->refresh()->position])->toBe([1, 2, 3]);

    $this->put(route('settings.facilities.reorder', $property->code), ['order' => [$second->id, $first->id]])
        ->assertInertiaFlash('toast.type', 'error');
    expect($second->refresh()->position)->toBe(3);
});

test('owners add, change and delete sessions of a Shared facility', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $walk = Facility::factory()->for($property)->shared()->create(['name' => 'Night Walk']);
    $this->actingAs($owner);

    $this->post(route('settings.facilities.sessions.store', [$property->code, $walk]), ['starts_at' => '20:00', 'ends_at' => '22:00'])
        ->assertSessionHasNoErrors();
    $session = FacilitySession::query()->sole();
    expect($session->property_id)->toBe($property->id);

    $this->post(route('settings.facilities.sessions.store', [$property->code, $walk]), ['starts_at' => '20:00', 'ends_at' => '22:00'])
        ->assertSessionHasErrors(['ends_at' => 'This facility already has a session at these times.']);
    $this->post(route('settings.facilities.sessions.store', [$property->code, $walk]), ['starts_at' => '08:30', 'ends_at' => '06:30'])
        ->assertSessionHasErrors(['ends_at' => 'The session must end after it starts.']);

    $this->put(route('settings.facilities.sessions.update', [$property->code, $walk, $session]), ['starts_at' => '06:30', 'ends_at' => '08:30'])
        ->assertSessionHasNoErrors();
    expect(substr($session->refresh()->starts_at, 0, 5))->toBe('06:30');

    $this->get(route('settings.facilities.sessions.index', [$property->code, $walk]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/facilities/sessions')
            ->where('facility.name', 'Night Walk')
            ->where('sessions.0.startsAt', '06:30'));

    $this->delete(route('settings.facilities.sessions.destroy', [$property->code, $walk, $session]))->assertSessionHasNoErrors();
    expect(FacilitySession::query()->count())->toBe(0);
    expect(Activity::query()->whereIn('event', ['facility_session.created', 'facility_session.updated', 'facility_session.deleted'])->count())->toBe(3);
});

test('only a Shared facility has sessions, and saving one as Private deletes them', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $hall = Facility::factory()->for($property)->create();
    $walk = Facility::factory()->for($property)->shared()->create(['name' => 'Night Walk']);
    FacilitySession::factory()->for($walk)->create(['property_id' => $property->id]);
    $this->actingAs($owner);

    $this->get(route('settings.facilities.sessions.index', [$property->code, $hall]))->assertNotFound();
    $this->post(route('settings.facilities.sessions.store', [$property->code, $hall]), ['starts_at' => '20:00', 'ends_at' => '22:00'])
        ->assertInertiaFlash('toast.type', 'error');
    expect($hall->sessions()->count())->toBe(0);

    $this->put(route('settings.facilities.update', [$property->code, $walk]), facilityPayload(['name' => 'Night Walk', 'sharing' => 'private']))
        ->assertSessionHasNoErrors();
    expect($walk->sessions()->count())->toBe(0);
});

test('facility settings need the feature, settings.manage and the same property', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $other = Facility::factory()->create();
    $otherSession = FacilitySession::factory()->create();

    $this->actingAs($owner)->put(route('settings.facilities.update', [$property->code, $other]), facilityPayload())->assertNotFound();
    $this->get(route('settings.facilities.sessions.index', [$property->code, $otherSession->facility_id]))->assertNotFound();

    $clerk = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $this->actingAs($clerk)->get(route('settings.facilities.index', $property->code))->assertForbidden();

    Feature::for($property)->deactivate(Facilities::class);
    $this->actingAs($owner)->get(route('settings.facilities.index', $property->code))->assertNotFound();
});
