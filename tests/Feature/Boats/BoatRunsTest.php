<?php

use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Boat;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * An owner of a property with the Boats feature on and a Boat in departure at 09:00 from Jetty Pelancongan.
 *
 * @return array{owner: User, property: Property, departure: BoatDeparture}
 */
function boatRunsSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    Feature::for($property)->activate(Boats::class);
    $departure = BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'Jetty Pelancongan']);

    return ['owner' => $owner, 'property' => $property, 'departure' => $departure];
}

test('owners add and change boats, and a name is used once per property', function () {
    $setup = boatRunsSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['owner'])->post(route('settings.boats.boats.store', $code), ['name' => 'SA10716', 'registration' => ''])->assertSessionHasNoErrors();
    $boat = Boat::query()->sole();
    expect($boat->name)->toBe('SA10716')->and($boat->registration)->toBeNull()->and($boat->active)->toBeTrue();

    $this->post(route('settings.boats.boats.store', $code), ['name' => 'sa10716'])->assertSessionHasErrors('name');
    $this->put(route('settings.boats.boats.update', [$code, $boat]), ['name' => 'SA10716', 'registration' => 'SAB 123', 'active' => '0'])->assertSessionHasNoErrors();

    expect($boat->refresh()->registration)->toBe('SAB 123')->and($boat->active)->toBeFalse();

    $this->get(route('settings.boats.boats.index', $code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/boats/boats')->where('boats.0.name', 'SA10716'));
});

test('the boats tab needs settings.manage and another property boat is a 404', function () {
    $setup = boatRunsSetup();
    $other = Boat::factory()->create();
    $frontDesk = User::factory()->for($setup['property'])->withPermissions(['reports.view'])->create();

    $this->actingAs($frontDesk)->get(route('settings.boats.boats.index', $setup['property']->code))->assertForbidden();
    $this->actingAs($setup['owner'])->put(route('settings.boats.boats.update', [$setup['property']->code, $other]), ['name' => 'X'])->assertNotFound();
});

test('the charter charge is an active item with a fixed price', function () {
    $setup = boatRunsSetup();
    $charter = ChargeItem::factory()->for($setup['property'])->create(['default_amount' => '280.00']);
    $priceless = ChargeItem::factory()->for($setup['property'])->create(['default_amount' => null]);
    $url = route('settings.boats.charter', $setup['property']->code);

    $this->actingAs($setup['owner'])->put($url, ['charge_item_id' => $priceless->id])->assertSessionHasErrors('charge_item_id');
    $this->put($url, ['charge_item_id' => $charter->id])->assertSessionHasNoErrors();

    expect($setup['property']->settings->refresh()->charter_charge_item_id)->toBe($charter->id);

    $this->put($url, ['charge_item_id' => null])->assertSessionHasNoErrors();

    expect($setup['property']->settings->refresh()->charter_charge_item_id)->toBeNull();
});
