<?php

use App\Enums\FacilityBookedBy;
use App\Enums\FacilityBookingStatus;
use App\Enums\PermissionEnum;
use App\Enums\TransactionCategory;
use App\Features\Facilities;
use App\Models\ChargeItem;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
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
        'booked_by' => 'item',
        'capacity' => '1',
        'charge_by_hand' => false,
        'default_amount' => '1500',
        'rate_basis' => 'day',
    ], $overrides);
}

test('owners add a facility with its own Facility charge item at the end of the list', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    Facility::factory()->for($property)->create(['position' => 4]);

    $response = $this->actingAs($owner)->post(route('settings.facilities.store', $property->code), facilityPayload())
        ->assertSessionHasNoErrors();

    $hall = Facility::query()->where('name', 'Riverside Hall')->sole();
    $response->assertRedirect(route('settings.facilities.show', [$property->code, $hall]));
    $chargeItem = $hall->chargeItem()->with('transactionCode')->sole();

    expect($hall->booked_by)->toBe(FacilityBookedBy::Item)
        ->and($hall->capacity)->toBe(1)
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
        ->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['name' => 'River Hall', 'capacity' => '1', 'charge_by_hand' => true, 'default_amount' => '900']))
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
    $this->post(route('settings.facilities.store', $property->code), facilityPayload(['capacity' => '0', 'booked_by' => 'whole']))
        ->assertSessionHasErrors(['capacity', 'booked_by']);

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

test('owners add, change and delete sessions of a facility', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $walk = Facility::factory()->for($property)->byPerson()->create(['name' => 'Night Walk']);
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

test('a facility booked by item takes sessions too', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $onsen = Facility::factory()->for($property)->create(['name' => 'Private onsen']);
    $this->actingAs($owner);

    $this->get(route('settings.facilities.sessions.index', [$property->code, $onsen]))->assertOk();
    $this->post(route('settings.facilities.sessions.store', [$property->code, $onsen]), ['starts_at' => '19:00', 'ends_at' => '20:00'])
        ->assertSessionHasNoErrors();

    expect($onsen->sessions()->count())->toBe(1);
});

test('Booked by cannot change while the facility has an upcoming booking', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $walk = Facility::factory()->for($property)->byPerson()->create(['name' => 'Night Walk']);
    $event = Event::factory()->withFolio()->create(['property_id' => $property->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20']);
    $booking = FacilityBooking::factory()->create(['event_id' => $event->id, 'facility_id' => $walk->id, 'quantity' => 4]);
    $this->actingAs($owner);

    $this->put(route('settings.facilities.update', [$property->code, $walk]), facilityPayload(['name' => 'Night Walk', 'booked_by' => 'item', 'rate_basis' => 'each']))
        ->assertInertiaFlash('toast.type', 'error');
    expect($walk->refresh()->booked_by)->toBe(FacilityBookedBy::Person)
        ->and(Activity::query()->where('event', 'facility.updated')->exists())->toBeFalse();

    $booking->update(['status' => FacilityBookingStatus::Cancelled]);

    $this->put(route('settings.facilities.update', [$property->code, $walk]), facilityPayload(['name' => 'Night Walk', 'booked_by' => 'item', 'rate_basis' => 'each']))
        ->assertSessionHasNoErrors();
    expect($walk->refresh()->booked_by)->toBe(FacilityBookedBy::Item);
});

test('facility settings need the feature, settings.manage and the same property', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $other = Facility::factory()->create();
    $otherSession = FacilitySession::factory()->create();

    $this->actingAs($owner)->put(route('settings.facilities.update', [$property->code, $other]), facilityPayload())->assertNotFound();
    $this->get(route('settings.facilities.sessions.index', [$property->code, $otherSession->facility_id]))->assertNotFound();
    $this->get(route('settings.facilities.show', [$property->code, $other]))->assertNotFound();

    $clerk = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $this->actingAs($clerk)->get(route('settings.facilities.index', $property->code))->assertForbidden();

    Feature::for($property)->deactivate(Facilities::class);
    $this->actingAs($owner)->get(route('settings.facilities.index', $property->code))->assertNotFound();
});

test('the facility page saves its taxes with the details, and the Available switch keeps them', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $hall = Facility::factory()->for($property)->create(['name' => 'Riverside Hall']);
    $sst = Tax::factory()->for($property)->create(['name' => 'SST']);
    $otherPropertyTax = Tax::factory()->create();
    $this->actingAs($owner);

    $this->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['capacity' => '1', 'tax_ids' => [$otherPropertyTax->id]]))
        ->assertSessionHasErrors('tax_ids.0');

    $this->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['capacity' => '1', 'tax_ids' => [$sst->id]]))
        ->assertSessionHasNoErrors();
    expect(Activity::query()->where('event', 'facility.updated')->sole()->properties['changed'])->toBe(['tax_ids']);

    $this->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['capacity' => '1', 'active' => '0']));
    expect($hall->chargeItem->transactionCode->applicableTaxes()->pluck('taxes.id')->all())->toBe([$sst->id]);

    $this->get(route('settings.facilities.show', [$property->code, $hall]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/facilities/show')
            ->where('facility.taxIds', [$sst->id])
            ->where('facility.taxNames', ['SST'])
            ->where('facility.active', false)
            ->has('taxes', 1));

    $this->put(route('settings.facilities.update', [$property->code, $hall]), facilityPayload(['capacity' => '1', 'tax_ids' => []]));
    expect($hall->chargeItem->transactionCode->applicableTaxes()->count())->toBe(0);
});

test('the charge item page sends a facility charge item to its facility page', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $hall = Facility::factory()->for($property)->create();
    $this->actingAs($owner);

    $this->get(route('settings.charge-items.edit', [$property->code, $hall->charge_item_id]))
        ->assertRedirect(route('settings.facilities.show', [$property->code, $hall]));

    Feature::for($property)->deactivate(Facilities::class);
    $this->get(route('settings.charge-items.edit', [$property->code, $hall->charge_item_id]))
        ->assertOk();
});

test('Settings › Charge items leaves out facility charge items, and staff cannot pick the Facility category', function () {
    ['owner' => $owner, 'property' => $property] = facilitySetup();
    $hall = Facility::factory()->for($property)->create();
    $breakfast = ChargeItem::factory()->for($property)->create(['name' => 'Breakfast', 'position' => 1]);
    $laundry = ChargeItem::factory()->for($property)->create(['name' => 'Laundry', 'position' => 2]);
    $this->actingAs($owner);

    $this->get(route('settings.charge-items.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('chargeItems', fn ($items) => collect($items)->pluck('name')->sort()->values()->all() === ['Breakfast', 'Laundry'])
            ->where('categories', fn ($categories) => ! collect($categories)->contains('value', TransactionCategory::Facility->value)));

    // The list sends only the items it shows, so the reorder works without the facility's charge item.
    $this->put(route('settings.charge-items.reorder', $property->code), ['order' => [$laundry->id, $breakfast->id]])
        ->assertSessionHasNoErrors();
    expect($laundry->refresh()->position)->toBe(1)->and($breakfast->refresh()->position)->toBe(2);

    $this->post(route('settings.charge-items.store', $property->code), ['name' => 'Spa', 'code' => 'SPA', 'category' => TransactionCategory::Facility->value])
        ->assertSessionHasErrors('category');
    expect(ChargeItem::query()->where('name', 'Spa')->exists())->toBeFalse()
        ->and($hall->chargeItem->refresh()->active)->toBeTrue();
});
