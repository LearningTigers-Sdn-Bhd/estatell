<?php

use App\Enums\BillingMode;
use App\Enums\BookingSourceType;
use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Notifications\PortalBookingCreated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PortalBookingSetup.php';

test('a booking is Confirmed, billed to the company and sourced as Company / Travel agent', function () {
    Notification::fake();
    $setup = portalBookingSetup();
    portalAutoAddOn($setup['property']);

    $response = portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['guest_name' => 'Tan Wei Ming', 'guest_phone' => '012 345 6789'])], [
        'group_name' => 'Tour BT-118',
        'external_reference' => 'BT-2026-118',
        'expected_total' => '210.00',
    ]));

    $reservation = Reservation::query()->sole();
    $response->assertRedirect(route('portal.bookings.show', ['property' => $setup['property'], 'reservation' => $reservation->id]));

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->company_id)->toBe($setup['company']->id)
        ->and($reservation->billing_mode)->toBe(BillingMode::MasterPays)
        ->and($reservation->created_by)->toBe($setup['portalUser']->id)
        ->and($reservation->external_reference)->toBe('BT-2026-118')
        ->and($reservation->group_name)->toBe('Tour BT-118')
        ->and($reservation->bookingSource->type)->toBe(BookingSourceType::Company)
        ->and($reservation->booker->full_name)->toBe('Tan Wei Ming')
        ->and($reservation->booker->phone)->toBe('+60123456789')
        ->and($reservation->units()->count())->toBe(1)
        ->and($reservation->folios()->where('type', 'master')->sole()->payer_company_id)->toBe($setup['company']->id)
        ->and(UnitAddOn::query()->sole()->chargeItem->name)->toBe('Cleaning fee');

    $log = Activity::query()->where('event', 'reservation.created')->sole();

    expect($log->causer_id)->toBe($setup['portalUser']->id);
});

test('the reservation staff are notified after a booking', function () {
    Notification::fake();
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)]));

    Notification::assertSentTo($setup['user'], PortalBookingCreated::class, fn (PortalBookingCreated $notification): bool => $notification->reference === Reservation::query()->sole()->reference
        && $notification->companyName === 'Borneo Travel');
    Notification::assertNotSentTo($setup['portalUser'], PortalBookingCreated::class);
});

test('guests are always new records, even when the name matches an existing guest', function () {
    $setup = portalBookingSetup();
    $before = Guest::query()->count();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['guest_name' => $setup['guest']->full_name, 'guest_phone' => $setup['guest']->phone])]));

    $reservation = Reservation::query()->sole();

    expect(Guest::query()->count())->toBe($before + 1)
        ->and($reservation->booker_guest_id)->not->toBe($setup['guest']->id)
        ->and($reservation->booker->full_name)->toBe('Tan Wei Ming');
});

test('several lines make a group: the first guest is the booker and each line keeps its guest', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup), portalRoomLine($setup)], ['expected_total' => '400.00']))->assertSessionHasNoErrors();

    $reservation = Reservation::query()->with('units.guests.guest')->sole();

    expect($reservation->units)->toHaveCount(2)
        ->and($reservation->booker->full_name)->toBe('Guest 1')
        ->and($reservation->units->map(fn ($unit) => $unit->guests->sole()->guest->full_name)->all())->toBe(['Guest 1', 'Guest 2']);
});

test('a dorm line books one unit for each bed', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalDormLine($setup, 2)], ['expected_total' => '160.00']))->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->count())->toBe(2)
        ->and(Reservation::query()->sole()->units->every(fn ($unit) => $unit->adults === 1))->toBeTrue();
});

test('a booking can have children with ages', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['child_ages' => [6]])]))->assertSessionHasNoErrors();

    $unit = ReservationUnit::query()->sole();

    expect($unit->children)->toBe(1)
        ->and($unit->childAges()->pluck('age')->all())->toBe([6]);
});

test('a refusal writes nothing and shows its message', function (string $case, string $key, string $message) {
    Notification::fake();
    $setup = portalBookingSetup();
    $body = portalBookingBody($setup, [portalRoomLine($setup)]);

    switch ($case) {
        case 'price changed':
            $body['expected_total'] = '199.00';
            break;
        case 'plan hidden':
            $setup['plan']->forceFill(['company_access' => 'everyone'])->save();
            break;
        case 'no guest name':
            $body['lines'][0]['guest_name'] = '  ';
            break;
        case 'too many adults':
            $body['lines'][0]['adults'] = 3;
            break;
        case 'too many children':
            $body['lines'][0]['child_ages'] = [3, 5];
            break;
        case 'not available':
            collect($setup['rooms'])->each(fn ($room) => $room->forceFill(['archived_at' => now()])->save());
            break;
        case 'restriction':
            Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', '2026-10-20')->update(['min_stay' => 3]);
            break;
        case 'credit limit':
            $setup['company']->forceFill(['credit_limit' => '50.00'])->save();
            break;
        case 'suspended':
            $setup['company']->forceFill(['status' => 'suspended'])->save();
            break;
        case 'in the past':
            $body['arrival_date'] = '2026-10-13';
            $body['departure_date'] = '2026-10-15';
            break;
    }

    $before = portalCounts();
    $response = portalBook($setup, $body);

    $response->assertSessionHasErrors([$key => $message]);
    expect(portalCounts())->toBe($before);
    Notification::assertNothingSent();
})->with([
    ['price changed', 'form', 'The price changed. Search again.'],
    ['plan hidden', 'form', 'This rate is not available. Search again.'],
    ['no guest name', 'lines.0.guest_name', 'Enter a guest name for each room.'],
    ['too many adults', 'lines.0.adults', 'Too many adults for this room type.'],
    ['too many children', 'lines.0.child_ages', 'Too many children for this room type.'],
    ['not available', 'lines.0.rate_plan_id', 'Not available on 20 Oct 2026.'],
    ['restriction', 'lines.0.rate_plan_id', 'Min stay 3 nights.'],
    ['credit limit', 'form', 'This booking would take the company over its credit limit. Contact the property.'],
    ['suspended', 'form', 'Your company can\'t book at this property right now. Contact the property.'],
    ['in the past', 'arrival_date', 'Arrival cannot be before today.'],
]);

test('a missing child age is refused', function () {
    $setup = portalBookingSetup();
    $before = portalCounts();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['child_ages' => [null]])]))
        ->assertSessionHasErrors(['lines.0.child_ages.0' => 'Enter the age of every child.']);

    expect(portalCounts())->toBe($before);
});

test('an unknown refusal shows the generic message and logs the code', function () {
    $setup = portalBookingSetup();
    $setup['roomType']->forceFill(['archived_at' => now()])->save();
    Log::spy();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)]))
        ->assertSessionHasErrors(['form' => 'This booking cannot be made. Contact the property.']);

    Log::shouldHaveReceived('warning')->with('Company portal booking refused.', ['code' => 'room_type_not_found'])->once();
});

test('a refusal in a later line saves no line, no guest and no reference', function () {
    $setup = portalBookingSetup();
    $before = portalCounts();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup), portalRoomLine($setup), portalRoomLine($setup)], ['expected_total' => '600.00']))
        ->assertSessionHasErrors(['lines.2.rate_plan_id']);

    expect(portalCounts())->toBe($before);

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)]));

    expect(Reservation::query()->sole()->reference)->toEndWith('0001');
});

test('the booking takes up to 20 lines', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, array_fill(0, 21, portalRoomLine($setup))))->assertSessionHasErrors(['lines']);
});

test('the company comes from the link, not from the request', function () {
    $setup = portalBookingSetup();
    $other = Company::factory()->for($setup['property'])->create();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], ['company_id' => $other->id]));

    expect(Reservation::query()->sole()->company_id)->toBe($setup['company']->id);
});

test('staff and users of another property cannot book', function () {
    $setup = portalBookingSetup();
    $before = portalCounts();

    $this->actingAs($setup['user'])->post(route('portal.bookings.store', $setup['property']), portalBookingBody($setup, [portalRoomLine($setup)]))->assertNotFound();
    $this->actingAs(User::factory()->companyUser()->create())->post(route('portal.bookings.store', $setup['property']), portalBookingBody($setup, [portalRoomLine($setup)]))->assertNotFound();

    expect(portalCounts())->toBe($before);
});
