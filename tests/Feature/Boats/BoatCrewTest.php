<?php

use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Boat;
use App\Models\BoatCrewMember;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * An owner of a property with the Boats feature on.
 *
 * @return array{owner: User, property: Property}
 */
function boatCrewSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    Feature::for($property)->activate(Boats::class);

    return ['owner' => $owner, 'property' => $property];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function boatCrewDeparturePayload(array $overrides = []): array
{
    return array_merge([
        'direction' => BoatDirection::In->value,
        'leaves_at' => '09:00',
        'arrives_at' => '09:45',
    ], $overrides);
}

test('owners add and change crew, log it, and turn them off without deleting', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    $code = $property->code;

    $this->actingAs($owner)->post(route('settings.boats.crew.store', $code), ['name' => 'Din', 'phone' => ' 013-222 1111 '])
        ->assertSessionHasNoErrors();

    $din = BoatCrewMember::query()->sole();
    expect($din->name)->toBe('Din')->and($din->phone)->toBe('+60132221111')->and($din->active)->toBeTrue();
    expect(Activity::query()->where('event', 'boat_crew_member.created')->count())->toBe(1);

    $this->post(route('settings.boats.crew.store', $code), ['name' => 'Awang', 'phone' => '12'])
        ->assertSessionHasErrors('phone');

    // The Available switch sends the full row with a new active.
    $this->put(route('settings.boats.crew.update', [$code, $din]), ['name' => 'Din', 'phone' => '013-222 1111', 'active' => '0'])
        ->assertSessionHasNoErrors();
    expect($din->refresh()->active)->toBeFalse();
    expect(Activity::query()->where('event', 'boat_crew_member.updated')->count())->toBe(1);

    // The Dialog sends no active, so an edit keeps the person's own state.
    $this->put(route('settings.boats.crew.update', [$code, $din]), ['name' => 'Din', 'phone' => '']);
    expect($din->refresh()->active)->toBeFalse()->and($din->phone)->toBeNull();

    $this->get(route('settings.boats.crew.index', $code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/boats/crew')->where('crew.0.name', 'Din'));
});

test('a crew name is used once per property, ignoring case', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    $code = $property->code;
    $awang = BoatCrewMember::factory()->for($property)->create(['name' => 'Awang']);
    BoatCrewMember::factory()->create(['name' => 'Daus']);

    $this->actingAs($owner)->post(route('settings.boats.crew.store', $code), ['name' => 'awang'])->assertSessionHasErrors('name');
    // The same name on another property is fine, and a member can keep their own name.
    $this->post(route('settings.boats.crew.store', $code), ['name' => 'daus'])->assertSessionHasNoErrors();
    $this->put(route('settings.boats.crew.update', [$code, $awang]), ['name' => 'AWANG'])->assertSessionHasNoErrors();

    expect(fn () => BoatCrewMember::factory()->for($property)->create(['name' => 'DAUS']))->toThrow(QueryException::class);
});

test('the crew tab needs settings.manage and another property crew member is a 404', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    $other = BoatCrewMember::factory()->create();
    $frontDesk = User::factory()->for($property)->withPermissions(['reports.view'])->create();

    $this->actingAs($frontDesk)->get(route('settings.boats.crew.index', $property->code))->assertForbidden();
    $this->actingAs($owner)->put(route('settings.boats.crew.update', [$property->code, $other]), ['name' => 'X'])->assertNotFound();
});

test('a departure saves one in charge and several assistants', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    [$awang, $daus, $din] = BoatCrewMember::factory()->for($property)->count(3)->sequence(['name' => 'Awang'], ['name' => 'Daus'], ['name' => 'Din'])->create();

    $this->actingAs($owner)->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload([
        'in_charge_id' => $awang->id,
        'assistant_ids' => [$daus->id, $din->id],
    ]))->assertSessionHasNoErrors();

    $departure = BoatDeparture::query()->sole();
    expect($departure->crew->pluck('name')->all())->toBe(['Awang', 'Daus', 'Din'])
        ->and($departure->crew->first()->pivot->in_charge)->toBeTrue()
        ->and($departure->crew->where('pivot.in_charge', true))->toHaveCount(1);

    // Change who is in charge: the old skipper becomes an assistant, with one in charge row throughout.
    $this->put(route('settings.boats.departures.update', [$property->code, $departure]), boatCrewDeparturePayload([
        'in_charge_id' => $daus->id,
        'assistant_ids' => [$awang->id],
    ]))->assertSessionHasNoErrors();

    $departure->load('crew');
    expect($departure->crew->pluck('name')->all())->toBe(['Daus', 'Awang'])
        ->and($departure->crew->first()->pivot->in_charge)->toBeTrue();

    $this->get(route('settings.boats.departures.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('departures.0.inChargeId', $daus->id)
            ->where('departures.0.assistantIds', [$awang->id])
            ->where('departures.0.crewNames', ['Daus', 'Awang'])
            ->has('crew', 3));

    // A save without crew fields keeps the crew (the Available switch).
    $this->put(route('settings.boats.departures.update', [$property->code, $departure]), boatCrewDeparturePayload(['active' => false]))
        ->assertSessionHasNoErrors();
    expect($departure->refresh()->crew()->count())->toBe(2);

    // Clearing the person in charge clears the crew.
    $this->put(route('settings.boats.departures.update', [$property->code, $departure]), boatCrewDeparturePayload(['in_charge_id' => '', 'assistant_ids' => []]))
        ->assertSessionHasNoErrors();
    expect($departure->crew()->count())->toBe(0);
});

test('the database allows only one in charge per departure', function () {
    $property = Property::factory()->create();
    $departure = BoatDeparture::factory()->for($property)->create();
    [$awang, $daus] = BoatCrewMember::factory()->for($property)->count(2)->create();

    DB::table('boat_departure_crew')->insert(['boat_departure_id' => $departure->id, 'boat_crew_member_id' => $awang->id, 'in_charge' => true]);

    expect(fn () => DB::table('boat_departure_crew')->insert(['boat_departure_id' => $departure->id, 'boat_crew_member_id' => $daus->id, 'in_charge' => true]))
        ->toThrow(QueryException::class);
});

test('assistants need someone in charge, and the assistants cannot repeat', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    [$awang, $daus] = BoatCrewMember::factory()->for($property)->count(2)->create();

    $this->actingAs($owner)
        ->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload(['assistant_ids' => [$daus->id]]))
        ->assertSessionHasErrors('assistant_ids');

    $this->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload(['in_charge_id' => $awang->id, 'assistant_ids' => [$daus->id, $daus->id]]))
        ->assertSessionHasErrors('assistant_ids.0');

    expect(BoatDeparture::query()->count())->toBe(0);
});

test('unavailable crew and crew of another property cannot be picked, but a current pick is kept', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    $awang = BoatCrewMember::factory()->for($property)->create();
    $razak = BoatCrewMember::factory()->for($property)->inactive()->create();
    $stranger = BoatCrewMember::factory()->create();

    $this->actingAs($owner);
    $this->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload(['in_charge_id' => $razak->id]))
        ->assertSessionHasErrors('in_charge_id');
    $this->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload(['in_charge_id' => $stranger->id]))
        ->assertSessionHasErrors('in_charge_id');
    $this->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload(['in_charge_id' => $awang->id, 'assistant_ids' => [$stranger->id]]))
        ->assertSessionHasErrors('assistant_ids.0');
    expect(BoatDeparture::query()->count())->toBe(0);

    $this->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload(['in_charge_id' => $awang->id, 'assistant_ids' => [$razak->id]]))
        ->assertSessionHasErrors('assistant_ids.0');

    // A person turned off after being picked stays on the departure when it is saved again.
    $this->post(route('settings.boats.departures.store', $property->code), boatCrewDeparturePayload(['in_charge_id' => $awang->id]))
        ->assertSessionHasNoErrors();
    $departure = BoatDeparture::query()->sole();
    $awang->update(['active' => false]);

    $this->put(route('settings.boats.departures.update', [$property->code, $departure]), boatCrewDeparturePayload(['pickup_point' => 'Jetty', 'in_charge_id' => $awang->id, 'assistant_ids' => []]))
        ->assertSessionHasNoErrors();
    expect($departure->crew()->pluck('boat_crew_members.id')->all())->toBe([$awang->id]);
});

test('boats save seats, the dialog keeps the state, and the switch names the change', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    $code = $property->code;

    $this->actingAs($owner)->post(route('settings.boats.boats.store', $code), ['name' => 'MV Sand Bay 1', 'seats' => '20'])->assertSessionHasNoErrors();
    $boat = Boat::query()->sole();
    expect($boat->seats)->toBe(20);

    foreach (['0', '-3', 'abc'] as $bad) {
        $this->put(route('settings.boats.boats.update', [$code, $boat]), ['name' => 'MV Sand Bay 1', 'seats' => $bad])->assertSessionHasErrors('seats');
    }

    $this->put(route('settings.boats.boats.update', [$code, $boat]), ['name' => 'MV Sand Bay 1', 'seats' => '', 'active' => '0'])->assertSessionHasNoErrors();
    expect($boat->refresh()->seats)->toBeNull()->and($boat->active)->toBeFalse();

    // The Dialog sends no active: the boat stays unavailable.
    $this->put(route('settings.boats.boats.update', [$code, $boat]), ['name' => 'MV Sand Bay 1', 'seats' => '12']);
    expect($boat->refresh()->seats)->toBe(12)->and($boat->active)->toBeFalse();

    $this->get(route('settings.boats.boats.index', $code))
        ->assertInertia(fn (Assert $page) => $page->where('boats.0.seats', 12));

    // Last, because a failed statement aborts the test transaction.
    expect(fn () => DB::table('boats')->where('id', $boat->id)->update(['seats' => 0]))->toThrow(QueryException::class);
});

test('the charter charge is on the fares tab and saves there', function () {
    ['owner' => $owner, 'property' => $property] = boatCrewSetup();
    $item = ChargeItem::factory()->for($property)->create(['name' => 'Charter Boat', 'default_amount' => '450.00']);

    $this->actingAs($owner)->get(route('settings.boats.fares.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/boats/fares')->where('charterChargeItemId', null)->where('chargeItems.0.label', 'Charter Boat'));

    $this->put(route('settings.boats.charter', $property->code), ['charge_item_id' => $item->id])->assertSessionHasNoErrors();

    $this->get(route('settings.boats.fares.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('charterChargeItemId', $item->id));
});
