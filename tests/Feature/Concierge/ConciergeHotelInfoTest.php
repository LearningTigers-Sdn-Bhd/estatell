<?php

use App\Actions\Concierge\AddInfoTopic;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Features\Concierge;
use App\Models\Amenity;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\PropertyInfoTopic;
use App\Models\PropertyTransportDetail;
use App\Models\RoomType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ConciergeSetup.php';

/**
 * @return array{0: User, 1: Property}
 */
function liveOwner(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(Concierge::class);

    return [$owner, $property];
}

it('lets staff save the hotel info, and logs only a real change', function () {
    [$owner, $property] = liveOwner();
    $payload = [
        'about' => '  A quiet guesthouse by the sea.  ',
        'arrival_instructions' => 'Ring the bell.',
        'departure_instructions' => '',
        'directions' => 'Turn left at the second light.',
        'airport_distance_km' => 25,
        'airport_travel_minutes' => 35,
        'airport_transfer_offered' => true,
        'airport_transfer_price' => '80.00',
        'airport_transfer_lead_hours' => 24,
        'pickup_point' => 'Arrival hall',
    ];

    $this->actingAs($owner)->put(route('settings.concierge.hotel-info.update', $property), $payload)->assertRedirect();
    $this->actingAs($owner)->put(route('settings.concierge.hotel-info.update', $property), $payload)->assertRedirect();

    $settings = $property->settings()->first();
    $transport = PropertyTransportDetail::query()->sole();
    expect($settings->about)->toBe('A quiet guesthouse by the sea.')
        ->and($settings->departure_instructions)->toBeNull()
        ->and($transport->airport_transfer_offered)->toBeTrue()
        ->and($transport->airport_transfer_price)->toBe('80.00')
        ->and(Activity::query()->where('event', 'concierge.hotel-info-updated')->count())->toBe(1);

    $this->actingAs($owner)->get(route('settings.concierge.index', $property))->assertInertia(fn (Assert $page) => $page
        ->where('hotelInfo.about', 'A quiet guesthouse by the sea.')
        ->where('hotelInfo.airportDistanceKm', 25));
});

it('shows the hotel info to guests, and a part with nothing filled does not show', function () {
    ['property' => $property] = conciergeSetup();
    $property->settings->forceFill(['about' => 'By the sea.', 'arrival_instructions' => 'Ring the bell.'])->save();
    PropertyTransportDetail::factory()->create(['property_id' => $property->id, 'airport_distance_km' => 25, 'airport_travel_minutes' => 35, 'airport_transfer_offered' => true, 'airport_transfer_price' => '80.00', 'airport_transfer_lead_hours' => 24]);
    PropertyInfoTopic::factory()->create(['property_id' => $property->id, 'title' => 'Laundry']);

    $this->get("/stay/{$property->code}/info")->assertInertia(fn (Assert $page) => $page
        ->where('details.about', 'By the sea.')
        ->where('details.arrivalInstructions', 'Ring the bell.')
        ->where('details.departureInstructions', null)
        ->where('details.transport.airport', '25 km · 35 min')
        ->where('details.transport.airportTransferPrice', fn ($price) => str_ends_with($price, '80.00'))
        ->where('details.topics.0.title', 'Laundry'));

    PropertyTransportDetail::query()->update(['directions' => null, 'airport_distance_km' => null, 'airport_travel_minutes' => null, 'airport_transfer_offered' => false, 'airport_transfer_price' => null]);
    $this->get("/stay/{$property->code}/info")->assertInertia(fn (Assert $page) => $page->where('details.transport', null));
});

it('saves structured parking details and words them for the guest', function () {
    [$owner, $property] = liveOwner();

    $this->actingAs($owner)->put(route('settings.concierge.hotel-info.update', $property), [
        'airport_transfer_offered' => false, 'parking_availability' => 'on_site', 'parking_type' => 'both', 'parking_price' => '10',
        'parking_price_unit' => 'night', 'parking_spaces' => 20, 'parking_height_limit_m' => '2.1', 'parking_ev_charging' => true,
        'parking_booking_required' => true,
    ])->assertRedirect();

    $transport = PropertyTransportDetail::query()->sole();
    expect($transport->parking_ev_charging)->toBeTrue()->and($transport->parking_spaces)->toBe(20);

    $this->get("/stay/{$property->code}/info")->assertInertia(fn (Assert $page) => $page
        ->where('details.transport.parkingAvailability', 'On-site')
        ->where('details.transport.parkingType', 'Self parking and valet')
        ->where('details.transport.parkingPrice', fn (string $price) => str_ends_with($price, '10.00 per night'))
        ->where('details.transport.parkingHeightLimit', '2.1 m')
        ->where('details.transport.parkingEvCharging', true)
        ->where('details.transport.parkingBookingRequired', true));

    $this->actingAs($owner)->put(route('settings.concierge.hotel-info.update', $property), ['airport_transfer_offered' => false, 'parking_availability' => 'none', 'parking_price' => '10', 'parking_ev_charging' => true])->assertRedirect();
    $this->get("/stay/{$property->code}/info")->assertInertia(fn (Assert $page) => $page
        ->where('details.transport.parkingAvailability', 'No parking')
        ->where('details.transport.parkingPrice', null)
        ->where('details.transport.parkingEvCharging', false));

    $this->actingAs($owner)->put(route('settings.concierge.hotel-info.update', $property), ['airport_transfer_offered' => false, 'parking_availability' => 'roof'])->assertSessionHasErrors('parking_availability');
});

it('tells the guest what the room types say about smoking and pets', function () {
    ['property' => $property] = conciergeSetup();
    $property->roomTypes()->update(['archived_at' => now()]);
    $this->get("/stay/{$property->code}/info/policies")->assertInertia(fn (Assert $page) => $page->where('policies.smoking', null)->where('policies.pets', null));

    RoomType::factory()->create(['property_id' => $property->id, 'smoking_allowed' => false, 'pets_allowed' => false]);
    $this->get("/stay/{$property->code}/info/policies")->assertInertia(fn (Assert $page) => $page->where('policies.smoking', 'Not allowed in rooms')->where('policies.pets', 'Not allowed'));

    RoomType::factory()->create(['property_id' => $property->id, 'pets_allowed' => true]);
    $this->get("/stay/{$property->code}/info/policies")->assertInertia(fn (Assert $page) => $page->where('policies.pets', 'Allowed in some rooms'));
});

it('saves the front desk phone, email and extension and shows them to the guest', function () {
    [$owner, $property] = liveOwner();

    $this->actingAs($owner)->put(route('settings.concierge.contacts.update', $property), [
        'front_desk_open_24h' => true, 'front_desk_phone' => '+60 3 1234 5678', 'front_desk_email' => 'desk@hotel.test', 'front_desk_extension' => '0',
    ])->assertRedirect();
    $this->get("/stay/{$property->code}/contact")->assertInertia(fn (Assert $page) => $page
        ->where('contacts.deskPhone', '+60 3 1234 5678')
        ->where('contacts.deskEmail', 'desk@hotel.test')
        ->where('contacts.deskExtension', '0'));

    $this->actingAs($owner)->put(route('settings.concierge.contacts.update', $property), ['front_desk_open_24h' => true, 'front_desk_email' => 'not-an-email'])->assertSessionHasErrors('front_desk_email');
});

it('gives the duty manager and the out-of-hours message only while the desk is closed', function () {
    ['property' => $property] = conciergeSetup();
    $property->settings->forceFill([
        'front_desk_open_24h' => false, 'front_desk_opens_at' => '08:00', 'front_desk_closes_at' => '20:00',
        'after_hours_message' => 'Call the duty manager.', 'duty_manager_phone' => '012-345 6789',
        'front_desk_whatsapp' => '+60 12 345 6789', 'emergency_services_number' => '999',
    ])->save();

    $this->travelTo(now($property->settings->time_zone)->setTime(12, 0));
    $this->get("/stay/{$property->code}/contact")->assertInertia(fn (Assert $page) => $page
        ->where('contacts.deskOpen', true)
        ->where('contacts.dutyManagerPhone', null)
        ->where('contacts.afterHoursMessage', null)
        ->where('contacts.whatsappUrl', 'https://wa.me/60123456789')
        ->where('contacts.emergencyServicesNumber', '999'));

    $this->travelTo(now($property->settings->time_zone)->setTime(23, 0));
    $this->get("/stay/{$property->code}/contact")->assertInertia(fn (Assert $page) => $page
        ->where('contacts.deskOpen', false)
        ->where('contacts.dutyManagerPhone', '012-345 6789')
        ->where('contacts.afterHoursMessage', 'Call the duty manager.')
        ->where('contacts.deskHours', '08:00 – 20:00'));
});

it('saves the guest contacts and needs the hours when the desk is not open all day', function () {
    [$owner, $property] = liveOwner();

    $this->actingAs($owner)->put(route('settings.concierge.contacts.update', $property), ['front_desk_open_24h' => false])->assertSessionHasErrors(['front_desk_opens_at', 'front_desk_closes_at']);
    $this->actingAs($owner)->put(route('settings.concierge.contacts.update', $property), ['front_desk_open_24h' => true, 'duty_manager_phone' => 'call me'])->assertSessionHasErrors('duty_manager_phone');
    $this->actingAs($owner)->put(route('settings.concierge.contacts.update', $property), ['front_desk_open_24h' => false, 'front_desk_opens_at' => '08:00', 'front_desk_closes_at' => '22:00', 'emergency_phone' => '03-1234 5678'])->assertRedirect();

    $this->actingAs($owner)->get(route('settings.concierge.index', $property))->assertInertia(fn (Assert $page) => $page
        ->where('contacts.frontDeskOpen24h', false)
        ->where('contacts.frontDeskOpensAt', '08:00')
        ->where('contacts.emergencyPhone', '03-1234 5678'));
});

it('lets staff keep extra topics, up to a limit, and refuses another property\'s topic and staff without the permission', function () {
    [$owner, $property] = liveOwner();
    $foreign = PropertyInfoTopic::factory()->create();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::GuestRequestsView])->create();

    $this->actingAs($owner)->post(route('settings.concierge.topics.store', $property), ['title' => 'Laundry', 'body' => 'Level 2.'])->assertRedirect();
    $this->actingAs($owner)->post(route('settings.concierge.topics.store', $property), ['title' => '', 'body' => 'x'])->assertSessionHasErrors('title');
    $this->actingAs($owner)->delete(route('settings.concierge.topics.destroy', [$property, $foreign]))->assertNotFound();
    $this->actingAs($staff)->post(route('settings.concierge.topics.store', $property), ['title' => 'X', 'body' => 'Y'])->assertForbidden();
    $this->actingAs($staff)->put(route('settings.concierge.hotel-info.update', $property), ['airport_transfer_offered' => false])->assertForbidden();

    PropertyInfoTopic::factory()->count(AddInfoTopic::MAX - 1)->create(['property_id' => $property->id]);
    $this->actingAs($owner)->post(route('settings.concierge.topics.store', $property), ['title' => 'Over', 'body' => 'The limit.'])->assertRedirect();
    expect($property->infoTopics()->count())->toBe(AddInfoTopic::MAX);

    $this->actingAs($owner)->delete(route('settings.concierge.topics.destroy', [$property, $property->infoTopics()->first()]))->assertRedirect();
    expect($property->infoTopics()->count())->toBe(AddInfoTopic::MAX - 1);
});

it('lets staff write the guest details of an amenity the property offers, and shows them to guests', function () {
    [$owner, $property] = liveOwner();
    $pool = Amenity::factory()->create(['name' => 'Pool', 'position' => 2]);
    $gym = Amenity::factory()->create(['name' => 'Gym', 'position' => 1]);
    $property->amenities()->attach([$pool->id, $gym->id]);
    $payload = ['location' => ' Level 5 ', 'opening_hours' => '7 am to 10 pm', 'fee_information' => '', 'reservation_required' => true, 'reservation_instructions' => 'Ask the front desk.', 'guest_notes' => ''];

    $this->actingAs($owner)->put(route('settings.concierge.amenities.update', [$property, $pool]), $payload)->assertRedirect();
    $this->actingAs($owner)->put(route('settings.concierge.amenities.update', [$property, $pool]), $payload)->assertRedirect();

    $detail = $property->amenities()->whereKey($pool->id)->first()->pivot;
    expect($detail->location)->toBe('Level 5')
        ->and($detail->fee_information)->toBeNull()
        ->and((bool) $detail->reservation_required)->toBeTrue()
        ->and(Activity::query()->where('event', 'concierge.amenity-details-updated')->count())->toBe(1)
        ->and($property->amenities()->whereKey($gym->id)->first()->pivot->location)->toBeNull();

    $this->actingAs($owner)->get(route('settings.concierge.index', $property))->assertInertia(fn (Assert $page) => $page
        ->has('amenityDetails', 2)
        ->where('amenityDetails.1.name', 'Pool')
        ->where('amenityDetails.1.openingHours', '7 am to 10 pm')
        ->has('amenities.amenities', 2)
        ->has('amenities.selectedIds', 2));

    $this->get("/stay/{$property->code}/info/amenities")->assertInertia(fn (Assert $page) => $page
        ->where('amenities.1.name', 'Pool')
        ->where('amenities.1.location', 'Level 5')
        ->where('amenities.1.reservationRequired', true)
        ->where('amenities.0.name', 'Gym')
        ->where('amenities.0.location', null));

    $property->amenities()->detach($pool->id);
    $property->amenities()->attach($pool->id);
    expect($property->amenities()->whereKey($pool->id)->first()->pivot->location)->toBeNull();
});

it('refuses details for an amenity the property does not offer, bad input, and staff without the permission', function () {
    [$owner, $property] = liveOwner();
    $offered = Amenity::factory()->create();
    $other = Amenity::factory()->create();
    $property->amenities()->attach($offered->id);
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::GuestRequestsView])->create();

    $this->actingAs($owner)->put(route('settings.concierge.amenities.update', [$property, $other]), ['reservation_required' => false])->assertNotFound();
    $this->actingAs($owner)->put(route('settings.concierge.amenities.update', [$property, $offered]), ['reservation_required' => false, 'location' => str_repeat('x', 201)])->assertSessionHasErrors('location');
    $this->actingAs($staff)->put(route('settings.concierge.amenities.update', [$property, $offered]), ['reservation_required' => false])->assertForbidden();
});

it('lets staff switch an amenity on and off from Concierge without the booking engine', function () {
    [$owner, $property] = liveOwner();
    $pool = Amenity::factory()->create(['name' => 'Pool']);

    expect(Feature::for($property)->active(BookingEngine::class))->toBeFalse();

    $this->actingAs($owner)->put(route('settings.concierge.amenities.toggle', $property), ['amenity_id' => $pool->id, 'active' => true])->assertRedirect();
    expect($property->amenities()->whereKey($pool->id)->exists())->toBeTrue();

    $this->put(route('settings.concierge.amenities.toggle', $property), ['amenity_id' => $pool->id, 'active' => false])->assertRedirect();
    expect($property->amenities()->whereKey($pool->id)->exists())->toBeFalse();
});

it('does not let an amenity switch work when Concierge is off', function () {
    $owner = User::factory()->owner()->create();
    $pool = Amenity::factory()->create();

    $this->actingAs($owner)->put(route('settings.concierge.amenities.toggle', $owner->property), ['amenity_id' => $pool->id, 'active' => true])->assertNotFound();
});

it('lets staff manage nearby places from Concierge without the booking engine', function () {
    [$owner, $property] = liveOwner();
    [$a, $b] = NearbyPlace::factory()->count(2)->create();
    $property->nearbyPlaces()->attach($a->id, ['position' => 1]);
    $suggested = NearbyPlace::factory()->create();

    expect(Feature::for($property)->active(BookingEngine::class))->toBeFalse();

    $this->actingAs($owner)->post(route('settings.concierge.nearby-places.suggested', [$property, $suggested]))->assertRedirect();
    $this->put(route('settings.concierge.nearby-places.update', [$property, $a]), ['description' => 'Five minutes by car'])->assertSessionHasNoErrors();
    $this->put(route('settings.concierge.nearby-places.active.update', [$property, $a]), ['active' => false])->assertRedirect();
    $this->put(route('settings.concierge.nearby-places.reorder', $property), ['order' => [$suggested->id, $a->id]])->assertSessionHasNoErrors();

    expect($property->nearbyPlaces()->pluck('nearby_places.id')->all())->toBe([$suggested->id, $a->id])
        ->and($property->nearbyPlaces()->whereKey($a->id)->first()->pivot->active)->toBeFalse();

    $this->delete(route('settings.concierge.nearby-places.destroy', [$property, $a]))->assertRedirect();

    expect($property->nearbyPlaces()->pluck('nearby_places.id')->all())->toBe([$suggested->id]);

    $this->get(route('settings.concierge.index', $property))->assertInertia(fn (Assert $page) => $page
        ->has('nearbyPlaces', 1)
        ->has('suggestions'));
});

it('does not let nearby place changes work when Concierge is off', function () {
    $owner = User::factory()->owner()->create();
    $place = NearbyPlace::factory()->create();

    $this->actingAs($owner)->post(route('settings.concierge.nearby-places.suggested', [$owner->property, $place]))->assertNotFound();
});
