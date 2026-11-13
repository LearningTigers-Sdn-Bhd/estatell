<?php

use App\Actions\Catalogues\SeedRoomRevenuePolicies;
use App\Enums\BookingEngineMode;
use App\Enums\FolioStatus;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Features\BookingEngine;
use App\Features\Concierge;
use App\Models\Amenity;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\GuestRequest;
use App\Models\NearbyPlace;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\PropertyFaq;
use App\Models\PropertyWifiNetwork;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ConciergeSetup.php';

it('shows the lobby as a hub of services, with the booking site only when it is published', function () {
    ['property' => $property] = conciergeSetup();
    $property->forceFill(['slug' => 'palm-resort'])->save();

    $this->get("/stay/{$property->code}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/lobby')
        ->where('walkInEnabled', false)
        ->where('bookingUrl', null));

    Feature::for($property)->activate(BookingEngine::class);
    PropertyBookingSite::factory()->for($property)->published()->create(['mode' => BookingEngineMode::Microsite]);

    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('bookingUrl', rtrim((string) config('estatell.booking_site_url'), '/').'/palm-resort'));
});

it('opens the booking code box from the hub', function () {
    ['property' => $property] = conciergeSetup();

    $this->get("/stay/{$property->code}/find")->assertOk()->assertInertia(fn (Assert $page) => $page->component('concierge/find')->where('property.code', $property->code));
});

it('answers 404 for every public guide page while the feature is off', function (string $path) {
    ['property' => $property] = conciergeSetup();
    Feature::for($property)->deactivate(Concierge::class);

    $this->get("/stay/{$property->code}/{$path}")->assertNotFound();
})->with(['find', 'contact', 'info', 'info/faqs', 'nearby']);

it('shows how to reach the property, with a map link from the owner or from the coordinates', function () {
    ['property' => $property] = conciergeSetup();
    $property->forceFill(['contact_phone' => '+60 3 1234 5678', 'contact_email' => 'hello@palm.test', 'address_line1' => '1 Beach Road', 'city' => 'Langkawi', 'postal_code' => '07000', 'google_maps_url' => 'https://maps.app.goo.gl/abc', 'latitude' => '6.3500000', 'longitude' => '99.8000000'])->save();

    $this->get("/stay/{$property->code}/contact")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/contact')
        ->where('details.phone', '+60 3 1234 5678')
        ->where('details.email', 'hello@palm.test')
        ->where('details.addressLines', ['1 Beach Road', '07000 Langkawi'])
        ->where('details.mapsUrl', 'https://maps.app.goo.gl/abc')
        ->where('back.href', "/stay/{$property->code}"));

    $property->forceFill(['google_maps_url' => null])->save();
    $this->get("/stay/{$property->code}/contact")->assertInertia(fn (Assert $page) => $page->where('details.mapsUrl', 'https://www.google.com/maps?q=6.3500000,99.8000000'));
});

it('gives the guest the room, payment and other policy texts', function () {
    ['property' => $property] = conciergeSetup();
    $property->settings->forceFill(['room_policy' => 'No pets.', 'payment_policy' => 'Deposit RM 100.'])->save();

    $this->get("/stay/{$property->code}/info/policies")->assertInertia(fn (Assert $page) => $page
        ->where('policies.roomPolicy', 'No pets.')
        ->where('policies.paymentPolicy', 'Deposit RM 100.')
        ->where('policies.otherPolicies', null));
});

it('shows the property guide sections: about, facilities, policies and FAQs', function () {
    ['property' => $property] = conciergeSetup();
    $property->settings->forceFill(['check_in_time' => '15:00:00', 'check_out_time' => '11:00:00', 'house_rules' => 'Quiet hours from 10 pm.'])->save();
    $property->amenities()->attach(Amenity::factory()->create(['name' => 'Pool']));
    PropertyFaq::factory()->create(['property_id' => $property->id, 'question' => 'Is breakfast included?', 'answer' => '7 to 10 am', 'position' => 1]);
    PropertyFaq::factory()->create(['property_id' => Property::factory()->create()->id, 'question' => 'Other property']);

    $this->get("/stay/{$property->code}/info")->assertInertia(fn (Assert $page) => $page
        ->component('concierge/info')
        ->where('section', 'property')
        ->where('details.checkInTime', '15:00')
        ->where('details.checkOutTime', '11:00')
        ->has('sections', 4)
        ->where('wifi', []));
    app(SeedRoomRevenuePolicies::class)($property);
    $property->roomRevenuePolicies()->where('code', SystemTransactionCode::CancellationRevenue)->update(['text_en' => 'Free until 48 hours before arrival.']);
    $this->get("/stay/{$property->code}/info/policies")->assertInertia(fn (Assert $page) => $page
        ->where('policies.houseRules', 'Quiet hours from 10 pm.')
        ->where('policies.checkInTime', '15:00')
        ->where('policies.terms.0.label', 'Cancellation')
        ->where('policies.terms.0.text', 'Free until 48 hours before arrival.'));
    $this->get("/stay/{$property->code}/info/amenities")->assertInertia(fn (Assert $page) => $page->has('amenities', 1)->where('amenities.0.name', 'Pool'));
    $this->get("/stay/{$property->code}/info/faqs")->assertInertia(fn (Assert $page) => $page->has('faqs', 1)->where('faqs.0.question', 'Is breakfast included?'));
});

it('never gives the Wi-Fi to a lobby visitor', function () {
    ['property' => $property] = conciergeSetup();
    PropertyWifiNetwork::factory()->create(['property_id' => $property->id, 'name' => 'Guest Wi-Fi', 'password' => 'secret99']);

    $this->get("/stay/{$property->code}/info/wifi")->assertNotFound();
    $this->get("/stay/{$property->code}/info")->assertInertia(fn (Assert $page) => $page->where('wifi', []));
});

it('shows the public places nearby, with the distance when the property has a location', function () {
    ['property' => $property] = conciergeSetup();
    $property->forceFill(['latitude' => 3.1390, 'longitude' => 101.6869])->save();
    $property->nearbyPlaces()->attach(NearbyPlace::factory()->create(['name' => 'Pasar Besar', 'latitude' => 3.1490, 'longitude' => 101.6869]), ['display_name' => 'Night market', 'description' => null, 'position' => 1, 'active' => true]);

    $this->get("/stay/{$property->code}/nearby")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/nearby')
        ->where('places.0.name', 'Night market')
        ->where('places.0.distance', '1.1 km')
        ->where('back.href', "/stay/{$property->code}"));
});

it('gives the Wi-Fi only to a guest who is checked in', function () {
    ['token' => $token, 'property' => $property, 'unit' => $unit] = conciergeInStaySetup();
    PropertyWifiNetwork::factory()->create(['property_id' => $property->id, 'name' => 'Guest Wi-Fi', 'password' => 'welcome2026', 'note' => 'Rooms']);
    PropertyWifiNetwork::factory()->create(['property_id' => Property::factory()->create()->id, 'name' => 'Other property']);

    $this->get("/c/{$token}/info/wifi")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('section', 'wifi')
        ->has('wifi', 1)
        ->where('wifi.0.name', 'Guest Wi-Fi')
        ->where('wifi.0.password', 'welcome2026')
        ->has('sections', 5)
        ->where('back.href', "/c/{$token}"));

    $unit->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null])->save();
    $this->get("/c/{$token}/info/wifi")->assertRedirect("/c/{$token}/info/property");
    $this->get("/c/{$token}/info/property")->assertInertia(fn (Assert $page) => $page->where('wifi', [])->has('sections', 4));
});

it('does not give the Wi-Fi to a guest who has checked out', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();
    PropertyWifiNetwork::factory()->create(['property_id' => $property->id]);
    $this->post("/c/{$token}/check-out");

    $this->get("/c/{$token}/info/wifi")->assertRedirect("/c/{$token}/info/property");
});

it('shows the contact page from a stay link', function () {
    ['token' => $token] = conciergeInStaySetup();

    $this->get("/c/{$token}/contact")->assertOk()->assertInertia(fn (Assert $page) => $page->component('concierge/contact')->where('back.href', "/c/{$token}"));
});

it('lists the main Wi-Fi network first and keeps a staff-only network from the guest', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);
    PropertyWifiNetwork::factory()->create(['property_id' => $property->id, 'name' => 'Lobby', 'position' => 1]);

    $this->actingAs($owner)->post(route('settings.concierge.wifi.store', $property), ['name' => 'Rooms', 'primary_network' => true])->assertRedirect();
    $this->actingAs($owner)->post(route('settings.concierge.wifi.store', $property), ['name' => 'Back office', 'active' => false])->assertRedirect();
    $this->actingAs($owner)->post(route('settings.concierge.wifi.store', $property), ['name' => 'Pool', 'primary_network' => true])->assertRedirect();
    auth()->logout();

    $this->get("/c/{$token}/info/wifi")->assertInertia(fn (Assert $page) => $page
        ->has('wifi', 3)
        ->where('wifi.0.name', 'Pool')
        ->where('wifi.0.primary', true)
        ->where('wifi.1.primary', false));
    expect(PropertyWifiNetwork::query()->where('primary_network', true)->count())->toBe(1);
});

it('lets staff change the main and guest switches of a network, in their own property only', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(Concierge::class);
    $first = PropertyWifiNetwork::factory()->create(['property_id' => $property->id, 'primary_network' => true]);
    $second = PropertyWifiNetwork::factory()->create(['property_id' => $property->id]);
    $other = PropertyWifiNetwork::factory()->create(['property_id' => Property::factory()->create()->id]);

    $this->actingAs($owner)->put(route('settings.concierge.wifi.update', [$property, $second]), ['active' => true, 'primary_network' => true])->assertRedirect();
    expect($first->refresh()->primary_network)->toBeFalse()->and($second->refresh()->primary_network)->toBeTrue();

    $this->actingAs($owner)->put(route('settings.concierge.wifi.update', [$property, $second]), ['active' => false, 'primary_network' => true]);
    $this->actingAs($owner)->put(route('settings.concierge.wifi.update', [$property, $second]), ['active' => false, 'primary_network' => true]);
    expect($second->refresh()->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'concierge.wifi-updated')->count())->toBe(2);

    $this->actingAs($owner)->put(route('settings.concierge.wifi.update', [$property, $other]), ['active' => false, 'primary_network' => false])->assertNotFound();
});

it('groups the questions under a topic', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(Concierge::class);

    $this->actingAs($owner)->post(route('settings.concierge.faqs.store', $property), ['topic' => ' Breakfast ', 'question' => 'When?', 'answer' => '7 to 10 am'])->assertRedirect();
    $this->actingAs($owner)->post(route('settings.concierge.faqs.store', $property), ['question' => 'Parking?', 'answer' => 'Basement'])->assertRedirect();

    expect(PropertyFaq::query()->orderBy('id')->pluck('topic')->all())->toBe(['Breakfast', null]);
    $this->get("/stay/{$property->code}/info/faqs")->assertInertia(fn (Assert $page) => $page->where('faqs.0.topic', 'Breakfast')->where('faqs.1.topic', null));
});

it('lets staff keep the Wi-Fi networks and FAQs, and encrypts the password', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(Concierge::class);

    $this->actingAs($owner)->post(route('settings.concierge.wifi.store', $property), ['name' => 'Guest Wi-Fi', 'password' => 'welcome2026', 'note' => 'Rooms'])->assertRedirect();
    $this->actingAs($owner)->post(route('settings.concierge.faqs.store', $property), ['question' => 'Is breakfast included?', 'answer' => '7 to 10 am'])->assertRedirect();
    $this->actingAs($owner)->put(route('settings.concierge.update', $property), ['walk_in_enabled' => false, 'refund_requests_enabled' => true, 'house_rules' => '  Quiet hours  '])->assertRedirect();

    $network = PropertyWifiNetwork::query()->sole();
    expect($network->password)->toBe('welcome2026')
        ->and(DB::table('property_wifi_networks')->value('password'))->not->toContain('welcome2026')
        ->and(PropertyFaq::query()->sole()->question)->toBe('Is breakfast included?')
        ->and($property->refresh()->settings->concierge_refund_requests_enabled)->toBeTrue()
        ->and($property->settings->house_rules)->toBe('Quiet hours')
        ->and(Activity::query()->get()->toJson())->not->toContain('welcome2026');

    $this->actingAs($owner)->get(route('settings.concierge.index', $property))->assertInertia(fn (Assert $page) => $page
        ->where('wifiNetworks.0.name', 'Guest Wi-Fi')
        ->where('faqs.0.answer', '7 to 10 am')
        ->where('refundRequestsEnabled', true)
        ->where('houseRules', 'Quiet hours')
        ->where('sections.amenities', 0)
        ->where('sections.nearbyPlaces', 0)
        ->has('policyTerms'));

    $this->actingAs($owner)->delete(route('settings.concierge.wifi.destroy', [$property, $network]))->assertRedirect();
    $this->actingAs($owner)->delete(route('settings.concierge.faqs.destroy', [$property, PropertyFaq::query()->sole()]))->assertRedirect();
    expect(PropertyWifiNetwork::query()->count())->toBe(0)->and(PropertyFaq::query()->count())->toBe(0);
});

it('refuses bad content, a list that is full, other properties\' rows and staff without the permission', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(Concierge::class);
    $foreign = PropertyWifiNetwork::factory()->create();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::GuestRequestsView])->create();

    $this->actingAs($owner)->post(route('settings.concierge.wifi.store', $property), ['name' => ''])->assertSessionHasErrors('name');
    $this->actingAs($owner)->post(route('settings.concierge.faqs.store', $property), ['question' => 'Q', 'answer' => ''])->assertSessionHasErrors('answer');
    $this->actingAs($owner)->delete(route('settings.concierge.wifi.destroy', [$property, $foreign]))->assertNotFound();
    $this->actingAs($staff)->post(route('settings.concierge.wifi.store', $property), ['name' => 'X'])->assertForbidden();

    PropertyWifiNetwork::factory()->count(10)->create(['property_id' => $property->id]);
    $this->actingAs($owner)->post(route('settings.concierge.wifi.store', $property), ['name' => 'Eleventh'])->assertRedirect();

    expect(PropertyWifiNetwork::query()->where('property_id', $property->id)->count())->toBe(10)->and($foreign->refresh()->id)->toBe($foreign->id);
});

it('opens the booking summary, own receipts and the invoice as PDFs, and nothing else', function () {
    ['token' => $token, 'unit' => $unit, 'folio' => $folio, 'property' => $property] = conciergeInStaySetup();
    $payment = Payment::factory()->create(['folio_id' => $folio->id, 'property_id' => $property->id, 'receipt_number' => 'RCP-0001', 'amount' => '50.00']);
    $other = kioskStayFor($property, 'R-300', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()], ['full_name' => 'Ben Lee']);
    $otherFolio = Folio::factory()->for($property)->forUnit($other['unit'])->create(['reservation_id' => $other['unit']->reservation_id]);
    $foreign = Payment::factory()->create(['folio_id' => $otherFolio->id, 'property_id' => $property->id, 'receipt_number' => 'RCP-0002']);

    $this->get("/c/{$token}/documents/summary")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get("/c/{$token}/documents/receipts/{$payment->id}")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get("/c/{$token}/documents/receipts/{$foreign->id}")->assertNotFound();
    $this->get("/c/{$token}/documents/invoice")->assertNotFound();

    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page
        ->has('documents', 2)
        ->where('documents.0.url', "/c/{$token}/documents/summary")
        ->where('documents.1.url', "/c/{$token}/documents/receipts/{$payment->id}"));

    $unit->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null])->save();
    $this->get("/c/{$token}/documents/summary")->assertNotFound();
});

it('offers the final invoice once the folio is closed', function () {
    ['token' => $token, 'folio' => $folio] = conciergeInStaySetup();
    FolioLine::factory()->for($folio)->create(['amount' => '100.00', 'unit_amount' => '100.00']);
    FolioLine::factory()->for($folio)->payment('100.00')->create();
    $folio->forceFill(['status' => FolioStatus::Closed, 'invoice_number' => 'INV-0001', 'closed_at' => now()])->save();

    $this->get("/c/{$token}/documents/invoice")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page->where('documents.1.label', 'Final invoice'));
});

it('shows a checked-out guest their documents and, when the property allows it, the refund form', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();
    $this->post("/c/{$token}/check-out");

    conciergeStep($token, 'checked_out')->assertInertia(fn (Assert $page) => $page->where('stay.canRequestRefund', false)->has('stay.documents', 2)->where('stay.documents.0.label', 'Booking summary')->where('stay.documents.1.label', 'Final invoice'));

    $property->settings->forceFill(['concierge_refund_requests_enabled' => true])->save();
    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page->where('stay.canRequestRefund', true));
});

it('sends a refund request to the staff queue, once, and only when the property allows it', function () {
    ['token' => $token, 'property' => $property, 'unit' => $unit] = conciergeInStaySetup();
    $this->post("/c/{$token}/check-out");

    $this->post("/c/{$token}/refund", ['details' => 'Charged twice'])->assertRedirect("/c/{$token}");
    expect(GuestRequest::query()->count())->toBe(0);

    $property->settings->forceFill(['concierge_refund_requests_enabled' => true])->save();
    $this->post("/c/{$token}/refund", ['details' => '  Charged twice for the minibar  '])->assertRedirect("/c/{$token}");
    $this->post("/c/{$token}/refund", ['details' => 'Again'])->assertRedirect("/c/{$token}");

    $request = GuestRequest::query()->sole();
    expect($request->type)->toBe(GuestRequestType::Refund)
        ->and($request->source)->toBe(GuestRequestSource::Concierge)
        ->and($request->status)->toBe(GuestRequestStatus::Open)
        ->and($request->details)->toBe('Charged twice for the minibar')
        ->and($request->opened_by)->toBeNull()
        ->and($request->reservation_unit_id)->toBe($unit->id);
    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page->where('stay.canRequestRefund', false));
});

it('refuses a refund request for a guest who is still in the house, and an empty one', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();
    $property->settings->forceFill(['concierge_refund_requests_enabled' => true])->save();

    $this->post("/c/{$token}/refund", ['details' => 'Too early'])->assertRedirect("/c/{$token}");
    $this->post("/c/{$token}/refund", ['details' => ''])->assertSessionHasErrors('details');

    expect(GuestRequest::query()->count())->toBe(0);
});

it('does not let a guest ask for a refund as a normal request', function () {
    ['token' => $token] = conciergeInStaySetup();

    $this->post("/c/{$token}/requests", ['type' => 'refund', 'details' => 'Money back'])->assertSessionHasErrors('type');
    $this->get("/c/{$token}/requests")->assertInertia(fn (Assert $page) => $page->has('types', 4));
});

it('keeps the stay link open for seven days after the stay, and no longer', function () {
    ['token' => $token] = conciergeInStaySetup();
    $this->post("/c/{$token}/check-out");

    $this->travelTo(now()->addDays(7));
    conciergeStep($token, 'checked_out');

    $this->travelTo(now()->addDays(2));
    $this->get("/c/{$token}")->assertNotFound();
});
