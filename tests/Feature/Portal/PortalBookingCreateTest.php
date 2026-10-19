<?php

use App\Enums\BillingMode;
use App\Enums\BookingSourceType;
use App\Enums\IdentityDocumentType;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
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

    $response = portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], [
        'guests' => [['full_name' => 'Tan Wei Ming', 'phone' => '012 345 6789']],
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

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], ['guests' => [['full_name' => $setup['guest']->full_name, 'phone' => $setup['guest']->phone]]]));

    $reservation = Reservation::query()->sole();

    expect(Guest::query()->count())->toBe($before + 1)
        ->and($reservation->booker_guest_id)->not->toBe($setup['guest']->id)
        ->and($reservation->booker->full_name)->toBe('Tan Wei Ming');
});

test('several lines make a group: the first guest is the booker and each unit keeps its guest', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup), portalRoomLine($setup)], ['expected_total' => '400.00']))->assertSessionHasNoErrors();

    $reservation = Reservation::query()->with('units.guests.guest')->sole();

    expect($reservation->units)->toHaveCount(2)
        ->and($reservation->booker->full_name)->toBe('Guest 1')
        ->and($reservation->units->map(fn ($unit) => $unit->guests->sole()->guest->full_name)->all())->toBe(['Guest 1', 'Guest 2']);
});

test('a dorm line books one unit for each bed, each with its own guest', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalDormLine($setup, 2)], ['expected_total' => '160.00']))->assertSessionHasNoErrors();

    $reservation = Reservation::query()->with('units.guests.guest')->sole();

    expect($reservation->units)->toHaveCount(2)
        ->and($reservation->units->every(fn ($unit) => $unit->adults === 1))->toBeTrue()
        ->and($reservation->units->map(fn ($unit) => $unit->guests->sole()->guest->full_name)->all())->toBe(['Guest 1', 'Guest 2']);
});

test('a line with several rooms books one unit for each room with the same plan and party', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['rooms' => 2, 'child_ages' => [6]])], ['expected_total' => '400.00']))->assertSessionHasNoErrors();

    $reservation = Reservation::query()->with('units.guests.guest')->sole();

    expect($reservation->units)->toHaveCount(2)
        ->and($reservation->units->every(fn ($unit) => $unit->rate_plan_id === $setup['plan']->id && $unit->adults === 2 && $unit->children === 1))->toBeTrue()
        ->and($reservation->units->map(fn ($unit) => $unit->guests->sole()->guest->full_name)->all())->toBe(['Guest 1', 'Guest 2']);
});

test('an ID typed for a guest is saved on that guest', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup, ['rooms' => 2])], [
        'expected_total' => '400.00',
        'guests' => [['full_name' => 'Tan Wei Ming'], ['full_name' => 'Lim Mei Ling', 'id_type' => IdentityDocumentType::MyKad->value, 'id_number' => '900101-14-5678']],
    ]))->assertSessionHasNoErrors();

    $units = Reservation::query()->sole()->units()->orderBy('id')->with('guests.guest.documents')->get();

    expect($units[0]->guests->sole()->guest->documents)->toHaveCount(0)
        ->and($units[1]->guests->sole()->guest->documents->sole()->type)->toBe(IdentityDocumentType::MyKad)
        ->and($units[1]->guests->sole()->guest->documents->sole()->number)->toBe('900101145678');
});

test('a guest ID needs both parts and a MyKad has 12 digits', function (array $guest, string $key) {
    $setup = portalBookingSetup();
    $before = portalCounts();

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], ['guests' => [['full_name' => 'Tan Wei Ming', ...$guest]]]))
        ->assertSessionHasErrors([$key]);

    expect(portalCounts())->toBe($before);
})->with([
    'number without type' => [['id_number' => 'A1234567'], 'guests.0.id_type'],
    'type without number' => [['id_type' => IdentityDocumentType::Passport->value], 'guests.0.id_number'],
    'short MyKad' => [['id_type' => IdentityDocumentType::MyKad->value, 'id_number' => '9001011'], 'guests.0.id_number'],
]);

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
            $body['guests'][0]['full_name'] = '  ';
            break;
        case 'more rooms than left':
            $body['lines'][0]['rooms'] = 3;
            $body['guests'] = [['full_name' => 'A'], ['full_name' => 'B'], ['full_name' => 'C']];
            break;
        case 'package nights':
            $setup['plan']->forceFill(['pricing_basis' => PricingBasis::Package, 'package_unit' => PackageUnit::PerGuest, 'fixed_nights' => 3])->save();
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
    ['price changed', 'form', 'The price changed. Check the new total and confirm again.'],
    ['plan hidden', 'lines.0.rate_plan_id', 'This rate is not available. Pick another rate.'],
    ['no guest name', 'guests.0.full_name', 'Enter a guest name for each room.'],
    ['more rooms than left', 'lines.0.rooms', 'Only 2 left for these dates.'],
    ['package nights', 'lines.0.room_type_id', 'This package sells a fixed number of nights.'],
    ['too many adults', 'lines.0.adults', 'Too many adults for this room type.'],
    ['too many children', 'lines.0.child_ages', 'Too many children for this room type.'],
    ['not available', 'lines.0.room_type_id', 'Not available on 20 Oct 2026.'],
    ['restriction', 'lines.0.room_type_id', 'Min stay 3 nights.'],
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
        ->assertSessionHasErrors(['lines.2.room_type_id']);

    expect(portalCounts())->toBe($before);

    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)]));

    expect(Reservation::query()->sole()->reference)->toEndWith('0001');
});

test('the booking takes up to 20 lines and 99 guests', function () {
    $setup = portalBookingSetup();

    portalBook($setup, portalBookingBody($setup, array_fill(0, 21, portalRoomLine($setup))))->assertSessionHasErrors(['lines']);
    portalBook($setup, portalBookingBody($setup, [portalRoomLine($setup)], ['guests' => array_fill(0, 100, ['full_name' => 'Guest'])]))
        ->assertSessionHasErrors(['guests' => 'A booking can have up to 99 rooms and beds.']);
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
