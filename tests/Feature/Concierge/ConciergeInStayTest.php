<?php

use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\Amenity;
use App\Models\AmenityCategory;
use App\Models\FolioLine;
use App\Models\GatewayPayment;
use App\Models\GuestRequest;
use App\Models\NearbyPlace;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TransactionCode;
use App\Services\Payments\CurlecGateway;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ConciergeSetup.php';
require_once __DIR__.'/../Support/CurlecSetup.php';

it('shows a checked-in guest the stay home with the key window over', function () {
    ['token' => $token] = conciergeInStaySetup();

    conciergeStep($token, 'home')->assertInertia(fn (Assert $page) => $page->where('stay.roomNumber', null)->where('stay.pin', null));
});

it('sends a guest who is not checked in back to the stay page from every in-stay page', function (string $path) {
    ['token' => $token, 'unit' => $unit] = conciergeInStaySetup();
    $unit->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null])->save();

    $this->get("/c/{$token}/{$path}")->assertRedirect("/c/{$token}");
})->with(['requests', 'amenities', 'nearby', 'bill']);

it('lists the guest\'s own requests, newest first, with a note only on a resolved one', function () {
    ['token' => $token, 'unit' => $unit, 'property' => $property] = conciergeInStaySetup();
    $other = kioskStayFor($property, 'R-300', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()], ['full_name' => 'Ben Lee']);
    GuestRequest::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id, 'details' => 'Older', 'opened_at' => now()->subHour()]);
    GuestRequest::factory()->resolved()->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id, 'details' => 'Newer', 'resolution_note' => 'Delivered', 'opened_at' => now()]);
    GuestRequest::factory()->cancelled()->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id, 'details' => 'Dropped', 'opened_at' => now()->subDay()]);
    GuestRequest::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $other['unit']->id, 'details' => 'Not mine']);

    $this->get("/c/{$token}/requests")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/requests')
        ->has('requests', 3)
        ->where('requests.0.details', 'Newer')
        ->where('requests.0.note', 'Delivered')
        ->where('requests.1.details', 'Older')
        ->where('requests.2.details', 'Dropped')
        ->where('requests.2.note', null)
        ->has('types', 4));
});

it('sends the guest\'s request to the same queue as staff requests, with the room and no staff opener', function () {
    ['token' => $token, 'unit' => $unit, 'room' => $room] = conciergeInStaySetup();

    $this->post("/c/{$token}/requests", ['type' => 'housekeeping', 'details' => '  Two extra towels  '])->assertRedirect("/c/{$token}/requests");

    $request = GuestRequest::query()->sole();
    expect($request->source)->toBe(GuestRequestSource::Concierge)
        ->and($request->opened_by)->toBeNull()
        ->and($request->reservation_unit_id)->toBe($unit->id)
        ->and($request->room_id)->toBe($room->id)
        ->and($request->status)->toBe(GuestRequestStatus::Open)
        ->and($request->details)->toBe('Two extra towels')
        ->and(Activity::query()->where('event', 'guest_request.opened')->sole()->causer_id)->toBeNull();
});

it('refuses an empty request, a bad type and a sixth open request', function () {
    ['token' => $token, 'unit' => $unit, 'property' => $property] = conciergeInStaySetup();

    $this->post("/c/{$token}/requests", ['type' => 'housekeeping', 'details' => ''])->assertSessionHasErrors('details');
    $this->post("/c/{$token}/requests", ['type' => 'room-service', 'details' => 'x'])->assertSessionHasErrors('type');

    GuestRequest::factory()->count(5)->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id]);
    $this->post("/c/{$token}/requests", ['type' => 'other', 'details' => 'One more'])->assertSessionHasErrors('details');

    expect(GuestRequest::query()->count())->toBe(5);
});

it('lets a guest ask again once the team has answered one', function () {
    ['token' => $token, 'unit' => $unit, 'property' => $property] = conciergeInStaySetup();
    GuestRequest::factory()->count(4)->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id]);
    GuestRequest::factory()->resolved()->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id]);

    $this->post("/c/{$token}/requests", ['type' => 'other', 'details' => 'A fifth open one'])->assertSessionHasNoErrors();

    expect(GuestRequest::query()->count())->toBe(6);
});

it('shows the amenities the property ticked, by category', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();
    $wellness = AmenityCategory::factory()->create(['name' => 'Wellness']);
    $pool = Amenity::factory()->inCategory($wellness)->create(['name' => 'Pool', 'position' => 1]);
    Amenity::factory()->inCategory($wellness)->create(['name' => 'Sauna']);
    $property->amenities()->attach($pool);

    $this->get("/c/{$token}/amenities")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/amenities')
        ->has('amenities', 1)
        ->where('amenities.0.name', 'Pool')
        ->where('amenities.0.category', 'Wellness'));
});

it('shows the active nearby places under the property\'s own name and note', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();
    $market = NearbyPlace::factory()->create(['name' => 'Pasar Besar']);
    $hidden = NearbyPlace::factory()->create(['name' => 'Hidden']);
    $property->nearbyPlaces()->attach($market, ['display_name' => 'Night market', 'description' => '5 minutes by car', 'position' => 1, 'active' => true]);
    $property->nearbyPlaces()->attach($hidden, ['display_name' => null, 'description' => null, 'position' => 2, 'active' => false]);

    $this->get("/c/{$token}/nearby")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/nearby')
        ->has('places', 1)
        ->where('places.0.name', 'Night market')
        ->where('places.0.description', '5 minutes by car')
        ->where('places.0.mapsUrl', $market->google_maps_url));
});

it('shows the bill, what is owed and that check-out waits for payment', function () {
    ['token' => $token, 'folio' => $folio] = conciergeInStaySetup();
    FolioLine::factory()->for($folio)->create(['amount' => '150.00', 'unit_amount' => '150.00', 'description' => 'Room charge', 'service_date' => '2026-10-12']);
    FolioLine::factory()->for($folio)->payment('50.00')->create();

    $this->get("/c/{$token}/bill")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/bill')
        ->has('bill.lines', 2)
        ->where('bill.lines.0.description', 'Room charge')
        ->where('bill.balance', '100.00')
        ->where('bill.due', '100.00')
        ->where('bill.credit', '0.00')
        ->where('bill.canPay', true)
        ->where('bill.canCheckOut', false)
        ->where('bill.checkOutOpensOn', null));
});

it('shows a held deposit as a credit the front desk gives back, and does not offer check-out', function () {
    ['token' => $token, 'folio' => $folio] = conciergeInStaySetup();
    FolioLine::factory()->for($folio)->payment('100.00')->create();

    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page
        ->where('bill.balance', '-100.00')
        ->where('bill.due', '0.00')
        ->where('bill.credit', '100.00')
        ->where('bill.canPay', false)
        ->where('bill.canCheckOut', false));
});

it('offers check-out only on the departure day', function () {
    ['token' => $token, 'unit' => $unit] = conciergeInStaySetup();
    $unit->forceFill(['departure_date' => '2026-10-16'])->save();

    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page->where('bill.canCheckOut', false)->where('bill.checkOutOpensOn', '2026-10-16'));
    $this->post("/c/{$token}/check-out")->assertRedirect("/c/{$token}/bill");

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn);
});

it('checks the guest out when the bill is settled, with the front desk rules', function () {
    ['token' => $token, 'unit' => $unit, 'property' => $property] = conciergeInStaySetup();

    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page->where('bill.canCheckOut', true));
    $this->post("/c/{$token}/check-out")->assertRedirect("/c/{$token}");
    $this->post("/c/{$token}/check-out")->assertStatus(404);

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and(Activity::query()->where('event', 'concierge.checked_out')->sole()->properties->all())->toBe(['property_id' => $property->id])
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out')->sole()->causer_id)->toBeNull();
    conciergeStep($token, 'checked_out');
});

it('does not check out a guest who still owes money', function () {
    ['token' => $token, 'unit' => $unit, 'folio' => $folio] = conciergeInStaySetup();
    FolioLine::factory()->for($folio)->create(['amount' => '20.00', 'unit_amount' => '20.00']);

    $this->post("/c/{$token}/check-out")->assertRedirect("/c/{$token}/bill");

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Activity::query()->where('event', 'concierge.checked_out')->count())->toBe(0);
});

it('lets a checked-out guest read the thank-you page and nothing else', function () {
    ['token' => $token, 'unit' => $unit] = conciergeInStaySetup();
    $this->post("/c/{$token}/check-out");

    conciergeStep($token, 'checked_out');
    $this->post("/c/{$token}/requests", ['type' => 'other', 'details' => 'x'])->assertNotFound();
    $this->post("/c/{$token}/identity", conciergeIdentityBody())->assertNotFound();
    $this->get("/c/{$token}/bill")->assertRedirect("/c/{$token}");

    expect($unit->refresh()->status)->toBe(UnitStatus::CheckedOut)->and(GuestRequest::query()->count())->toBe(0);
});

it('pays the open bill online, posting a settlement on the unit folio', function () {
    config(['services.curlec.key_id' => 'rzp_test_key', 'services.curlec.key_secret' => CURLEC_KEY_SECRET, 'services.curlec.webhook_secret' => CURLEC_WEBHOOK_SECRET, 'services.curlec.account' => 'primary']);
    ['token' => $token, 'folio' => $folio, 'property' => $property] = conciergeInStaySetup();
    $code = TransactionCode::factory()->for($property)->system(SystemTransactionCode::OnlinePayment)->create();
    PaymentMethod::factory()->create(['property_id' => $property->id, 'transaction_code_id' => $code->id, 'name' => 'Online', 'type' => PaymentMethodType::Online, 'requires_reference' => false]);
    $gateway = new FakeCurlecGateway;
    app()->instance(CurlecGateway::class, $gateway);
    FolioLine::factory()->for($folio)->create(['amount' => '75.00', 'unit_amount' => '75.00']);

    $order = $this->postJson("/c/{$token}/payment")->assertOk()->assertJsonPath('amount', 7500)->json('order_id');
    $this->post("/c/{$token}/payment/confirm", ['razorpay_order_id' => $order, 'razorpay_payment_id' => 'pay_bill', 'razorpay_signature' => conciergeSignature($order, 'pay_bill')])
        ->assertRedirect("/c/{$token}/bill");

    expect(Payment::query()->where('reference', 'pay_bill')->sole()->purpose)->toBe(PaymentPurpose::Settlement)
        ->and(GatewayPayment::query()->sole()->reservation_unit_id)->not->toBeNull();
    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page->where('bill.due', '0.00')->where('bill.canCheckOut', true));
});

it('starts no payment when the bill owes nothing', function () {
    ['token' => $token] = conciergeInStaySetup();
    app()->instance(CurlecGateway::class, new FakeCurlecGateway);

    $this->postJson("/c/{$token}/payment")->assertStatus(409);

    expect(GatewayPayment::query()->count())->toBe(0);
});

it('gives the stay screen the booking code, the nights and the documents, but not the room', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();

    $this->get("/c/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stay.step', 'home')
        ->where('stay.reference', 'R-100')
        ->where('stay.nights', 2)
        ->where('stay.roomNumber', null)
        ->where('stay.documents.0.label', 'Booking summary')
        ->has('stay.documents', 1));
});

it('opens the requests page with the type a stay screen tile picked, and ignores any other', function (string $query, string $expected) {
    ['token' => $token] = conciergeInStaySetup();

    $this->get("/c/{$token}/requests{$query}")->assertInertia(fn (Assert $page) => $page->where('selectedType', $expected));
})->with([
    'housekeeping tile' => ['?type=housekeeping', 'housekeeping'],
    'report an issue tile' => ['?type=complaint', 'complaint'],
    'no type' => ['', 'housekeeping'],
    'unknown type' => ['?type=spa', 'housekeeping'],
    'refund is never picked here' => ['?type=refund', 'housekeeping'],
]);
