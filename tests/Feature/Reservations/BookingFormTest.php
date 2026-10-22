<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Models\BookingSource;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Discount;
use App\Models\Guest;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * The booking form payload for one room line, with the booker already picked.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => $setup['phone']->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'master_pays',
        'lines' => [bookingFormLine($setup)],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingFormLine(array $setup, array $overrides = []): array
{
    return ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => [], ...$overrides];
}

function bookingFormUrl(array $setup, string $route): string
{
    return route($route, ['property' => $setup['property']->code]);
}

test('the booking pages need reservations.manage', function (string $route, DefaultRoleEnum $role, int $status) {
    $setup = bookingSetup();
    $user = User::factory()->withRole($role)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($user)->get(bookingFormUrl($setup, $route))->assertStatus($status);
})->with([
    ['reservations.new', DefaultRoleEnum::FrontDesk, 200],
    ['reservations.walk-in', DefaultRoleEnum::FrontDesk, 200],
    ['reservations.new', DefaultRoleEnum::Finance, 403],
    ['reservations.walk-in', DefaultRoleEnum::Housekeeping, 403],
]);

test('the new booking page sends the lists the form needs', function () {
    $setup = bookingSetup();
    $package = RatePlan::factory()->package(nights: 3)->for($setup['property'])->create();
    $package->roomTypes()->attach($setup['roomType']);
    Discount::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Staff rate', 'scope' => DiscountScope::RoomOnly]);
    Discount::factory()->create(['property_id' => $setup['property']->id, 'scope' => DiscountScope::AllCharges]);
    Discount::factory()->create(['property_id' => $setup['property']->id, 'scope' => DiscountScope::RoomOnly, 'active' => false]);
    ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Breakfast']);

    $this->actingAs($setup['user'])
        ->get(bookingFormUrl($setup, 'reservations.new'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservations/create')
            ->where('isWalkIn', false)
            ->where('sources', [['id' => $setup['phone']->id, 'name' => 'Phone', 'type' => 'direct', 'typeLabel' => 'Direct', 'isActive' => true], ['id' => $setup['ota']->id, 'name' => 'Booking.com', 'type' => 'ota', 'typeLabel' => 'OTA', 'isActive' => true]])
            ->where('today', '2026-10-10')
            ->where('checkInTime', '15:00')
            ->where('checkOutTime', '11:00')
            ->has('addUnitOptions.roomTypes', 2)
            ->has('addUnitOptions.ratePlans', 3)
            ->where('addUnitOptions.ratePlans.2.fixedNights', 3)
            ->where('addUnitOptions.ratePlans.0.fixedNights', null)
            ->has('chargeItems', 1)
            ->has('discounts', 1)
            ->where('discounts.0.name', 'Staff rate')
            ->has('billingModes', 3)
            ->where('canOverrideRestrictions', true)
            ->where('canManageGuests', true)
            ->has('countries')
            ->has('genders')
            ->has('locales'));
});

test('the walk-in page fixes the source and the business date', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->get(bookingFormUrl($setup, 'reservations.walk-in'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('isWalkIn', true)
            ->where('sources', [['id' => $setup['walkIn']->id, 'name' => 'Walk-in', 'type' => 'walk_in', 'typeLabel' => 'Walk-in', 'isActive' => true]])
            ->where('today', '2026-10-10'));
});

test('the booking page query count stays the same however much the property has', function () {
    $setup = bookingSetup();
    $count = function () use ($setup): int {
        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($setup['user'])->get(bookingFormUrl($setup, 'reservations.new'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $queries;
    };

    $count();
    $few = $count();

    foreach (range(1, 4) as $ignored) {
        $plan = RatePlan::factory()->for($setup['property'])->create();
        $plan->roomTypes()->attach($setup['roomType']);
        ChargeItem::factory()->create(['property_id' => $setup['property']->id]);
        Discount::factory()->create(['property_id' => $setup['property']->id, 'scope' => DiscountScope::RoomOnly]);
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(20);
});

test('a booking is saved and the user lands on the reservation page', function () {
    $setup = bookingSetup();

    $response = $this->actingAs($setup['user'])->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, [
        'group_name' => 'Tan family trip',
        'notes' => 'Needs a cot.',
        'external_reference' => 'OTA-1',
        'lines' => [
            bookingFormLine($setup, ['room_id' => $setup['rooms'][1]->id, 'child_ages' => [4, 9], 'override_total' => '150.00', 'override_reason' => 'Corporate rate']),
            ['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 2, 'bed_ids' => [$setup['beds'][0]->id, $setup['beds'][2]->id]],
        ],
    ]));

    $reservation = Reservation::query()->sole();

    $response->assertRedirect(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $reservation->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Reservation {$reservation->reference} created."]);

    $units = $reservation->units()->orderBy('id')->get();

    expect($reservation->group_name)->toBe('Tan family trip')
        ->and($reservation->notes)->toBe('Needs a cot.')
        ->and($reservation->external_reference)->toBe('OTA-1')
        ->and($reservation->booking_source_id)->toBe($setup['phone']->id)
        ->and($reservation->booker_guest_id)->toBe($setup['guest']->id)
        ->and($units)->toHaveCount(3)
        ->and($units[0]->childAges()->pluck('age')->all())->toBe([4, 9])
        ->and($units[0]->nights()->sum('amount'))->toEqual(150)
        ->and($units[0]->nights()->pluck('room_id')->unique()->all())->toBe([$setup['rooms'][1]->id])
        ->and($units[1]->nights()->pluck('bed_id')->unique()->all())->toBe([$setup['beds'][0]->id])
        ->and($units[2]->nights()->pluck('bed_id')->unique()->all())->toBe([$setup['beds'][2]->id])
        ->and($units->every(fn ($unit) => $unit->guests()->count() === 0))->toBeTrue();
});

test('a line can carry add-ons', function () {
    $setup = bookingSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00']);

    $this->actingAs($setup['user'])->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, [
        'lines' => [bookingFormLine($setup, ['add_ons' => [['charge_item_id' => $item->id, 'quantity' => 2]]])],
    ]))->assertSessionHasNoErrors();

    expect(Reservation::query()->sole()->units()->sole()->addOns()->sole()->quantity)->toBe(2);
});

test('a refused booking goes back with an error on the line and saves nothing', function (string $case) {
    $setup = bookingSetup();
    $other = bookingFormLine($setup);
    [$body, $key, $message] = match ($case) {
        'an arrival in the past' => [bookingBody($setup, ['arrival_date' => '2026-10-01', 'departure_date' => '2026-10-03']), 'arrival_date', 'Arrival cannot be before today.'],
        'no stock on the second line' => [bookingBody($setup, ['lines' => [$other, $other, $other]]), 'lines.2.room_type_id', 'No unit is available on 20 Oct 2026.'],
        'a plan not sold for the room type' => [bookingBody($setup, ['lines' => [$other, bookingFormLine($setup, ['rate_plan_id' => $setup['bedPlan']->id])]]), 'lines.1.rate_plan_id', 'This rate plan is not sold for the room type.'],
        'an override without a reason' => [bookingBody($setup, ['lines' => [bookingFormLine($setup, ['override_total' => '90.00'])]]), 'lines.0.override_reason', 'Enter a reason for the price override.'],
        'a bed that is not a bed of the dorm' => [bookingBody($setup, ['lines' => [['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 2, 'bed_ids' => [$setup['beds'][0]->id, 0]]]]), 'lines.0.bed_ids.1', 'This bed cannot be picked.'],
        'a room of another property' => [bookingBody($setup, ['lines' => [bookingFormLine($setup, ['room_id' => Room::factory()->create()->id])]]), 'lines.0.room_id', 'This room cannot be picked.'],
        'an unknown booker' => [bookingBody($setup, ['guests' => [['guest_id' => Guest::factory()->create()->id]]]), 'guests.0.guest_id', 'The booker is not one of this property\'s guests.'],
    };

    $this->actingAs($setup['user'])
        ->from(bookingFormUrl($setup, 'reservations.new'))
        ->post(bookingFormUrl($setup, 'reservations.store'), $body)
        ->assertRedirect(bookingFormUrl($setup, 'reservations.new'))
        ->assertSessionHasErrors([$key => $message]);

    expect(Reservation::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with(['an arrival in the past', 'no stock on the second line', 'a plan not sold for the room type', 'an override without a reason', 'a bed that is not a bed of the dorm', 'a room of another property', 'an unknown booker']);

test('a stay that breaks a restriction asks for a reason from staff who may override', function (DefaultRoleEnum $role, string $key) {
    $setup = bookingSetup();
    $user = User::factory()->withRole($role)->create(['property_id' => $setup['property']->id]);
    Rate::query()->where('date', '2026-10-21')->where('rate_plan_id', $setup['plan']->id)->update(['stop_sell' => true]);

    $this->actingAs($user)
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup))
        ->assertSessionHasErrors([$key]);

    expect(Reservation::query()->count())->toBe(0);
})->with([[DefaultRoleEnum::Owner, 'lines.0.restriction_override_reason'], [DefaultRoleEnum::FrontDesk, 'form']]);

test('a restriction override reason lets the booking through', function () {
    $setup = bookingSetup();
    Rate::query()->where('date', '2026-10-21')->where('rate_plan_id', $setup['plan']->id)->update(['stop_sell' => true]);

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['lines' => [bookingFormLine($setup, ['restriction_override_reason' => 'Owner approved'])]]))
        ->assertSessionHasNoErrors();

    expect(Reservation::query()->count())->toBe(1);
});

test('a walk-in needs a room or bed for every unit', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, [
            'booking_source_id' => $setup['walkIn']->id,
            'lines' => [bookingFormLine($setup), ['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 2, 'bed_ids' => [$setup['beds'][0]->id]]],
        ]))
        ->assertSessionHasErrors(['lines.0.room_id' => 'Select a room.', 'lines.1.bed_ids.1' => 'Select a bed.']);

    expect(Reservation::query()->count())->toBe(0);

    $this->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['booking_source_id' => $setup['walkIn']->id, 'arrival_date' => '2026-10-10', 'departure_date' => '2026-10-11', 'lines' => [bookingFormLine($setup, ['room_id' => $setup['rooms'][0]->id])]]))
        ->assertSessionHasErrors('form');
});

test('the booking form checks its shape', function (string $case) {
    $setup = bookingSetup();
    [$overrides, $field] = match ($case) {
        'no booker' => [['guests' => []], 'guests'],
        'no lines' => [['lines' => []], 'lines'],
        'no booking source' => [['booking_source_id' => null], 'booking_source_id'],
        'a booking source of another property' => [['booking_source_id' => BookingSource::factory()->create()->id], 'booking_source_id'],
        'an inactive booking source' => [['booking_source_id' => BookingSource::factory()->inactive()->create(['property_id' => $setup['property']->id])->id], 'booking_source_id'],
        'an OTA source without a booking number' => [['booking_source_id' => $setup['ota']->id], 'external_reference'],
        'a company source without a company' => [['booking_source_id' => $setup['companySource']->id], 'company_id'],
        'a bad date' => [['arrival_date' => 'soon'], 'arrival_date'],
        'a child age left empty' => [['lines' => [bookingFormLine($setup, ['child_ages' => [null]])]], 'lines.0.child_ages.0'],
        'notes that are too long' => [['notes' => str_repeat('a', 2001)], 'notes'],
        'a bad override amount' => [['lines' => [bookingFormLine($setup, ['override_total' => '9,5'])]], 'lines.0.override_total'],
    };

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, $overrides))
        ->assertSessionHasErrors($field);

    expect(Reservation::query()->count())->toBe(0);
})->with(['no booker', 'no lines', 'no booking source', 'a booking source of another property', 'an inactive booking source', 'an OTA source without a booking number', 'a company source without a company', 'a bad date', 'a child age left empty', 'notes that are too long', 'a bad override amount']);

test('the booking routes need reservations.manage and stay in their property', function () {
    $setup = bookingSetup();
    $viewer = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($viewer)->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup))->assertForbidden();

    expect(Reservation::query()->count())->toBe(0);
});

test('a booking needs the nationality of every guest', function (string $case) {
    $setup = bookingSetup();
    $unknown = Guest::factory()->create(['property_id' => $setup['property']->id, 'nationality_code' => null]);
    [$tab, $message] = match ($case) {
        'a new guest without one' => [['full_name' => 'Nur Aina'], 'Pick the nationality.'],
        'a picked guest with none on file' => [['guest_id' => $unknown->id], 'This guest has no nationality. Pick one and turn on Update guest profile.'],
        'an updated profile that clears it' => [['guest_id' => $setup['guest']->id, 'update_profile' => true, 'full_name' => 'Siti', 'nationality_code' => ''], 'Pick the nationality.'],
    };

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['guests' => [['stays_in' => 0, ...$tab]]]))
        ->assertSessionHasErrors(['guests.0.nationality_code' => $message]);

    expect(Reservation::query()->count())->toBe(0);
})->with(['a new guest without one', 'a picked guest with none on file', 'an updated profile that clears it']);

test('a picked guest with no nationality books once the profile update adds one', function () {
    $setup = bookingSetup();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Lim Wei Jie', 'nationality_code' => null]);

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['guests' => [['guest_id' => $guest->id, 'update_profile' => true, 'full_name' => 'Lim Wei Jie', 'nationality_code' => 'SG', 'stays_in' => 0]]]))
        ->assertSessionHasNoErrors();

    expect(Reservation::query()->count())->toBe(1)
        ->and($guest->fresh()->nationality_code)->toBe('SG');
});

test('staff without guests.manage see the missing nationality on the guest name', function () {
    $setup = bookingSetup();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, 'nationality_code' => null]);
    $user = User::factory()->create(['property_id' => $setup['property']->id]);
    actionsRoleWith($user, ['reservations.view', 'reservations.manage', 'guests.view']);

    $this->actingAs($user)
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['guests' => [['guest_id' => $guest->id, 'stays_in' => 0]]]))
        ->assertSessionHasErrors(['guests.0.full_name' => 'This guest has no nationality. Ask someone who can edit guests to add it.']);

    expect(Reservation::query()->count())->toBe(0);
});

test('a booking creates a guest, updates another and attaches both to their units', function () {
    $setup = bookingSetup();
    $other = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Ahmad Faiz', 'city' => 'Ipoh']);

    $this->actingAs($setup['user'])->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, [
        'lines' => [bookingFormLine($setup), bookingFormLine($setup)],
        'guests' => [
            ['guest_id' => $setup['guest']->id, 'stays_in' => 0],
            ['full_name' => 'Nur Aina', 'nationality_code' => 'MY', 'phone' => '012-888 2211', 'email' => 'aina@example.com', 'city' => 'Penang', 'date_of_birth' => '1990-05-04', 'stays_in' => 1],
            ['guest_id' => $other->id, 'update_profile' => true, 'full_name' => 'Ahmad Faiz', 'city' => 'Melaka', 'stays_in' => 1],
        ],
    ]))->assertSessionHasNoErrors();

    $reservation = Reservation::query()->sole();
    $units = $reservation->units()->orderBy('id')->get();
    $created = Guest::query()->where('full_name', 'Nur Aina')->sole();

    expect($reservation->booker_guest_id)->toBe($setup['guest']->id)
        ->and($created->phone)->toBe('+60128882211')
        ->and($created->city)->toBe('Penang')
        ->and($created->date_of_birth->toDateString())->toBe('1990-05-04')
        ->and($other->refresh()->city)->toBe('Melaka')
        ->and($units[0]->guests()->sole()->guest_id)->toBe($setup['guest']->id)
        ->and($units[1]->guests()->orderBy('id')->pluck('guest_id')->all())->toBe([$created->id, $other->id])
        ->and($units[1]->guests()->where('is_primary', true)->sole()->guest_id)->toBe($created->id);
});

test('a linked guest keeps its profile unless the update is on', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, [
        'guests' => [['guest_id' => $setup['guest']->id, 'full_name' => 'Changed Name', 'city' => 'Ipoh', 'stays_in' => 0]],
    ]))->assertSessionHasNoErrors();

    expect($setup['guest']->refresh()->full_name)->toBe('Tan Wei Ming')->and($setup['guest']->city)->not->toBe('Ipoh');
});

test('staff without guests.manage may only pick existing guests', function (array $tab, string $key, string $message) {
    $setup = bookingSetup();
    $user = User::factory()->create(['property_id' => $setup['property']->id]);
    actionsRoleWith($user, ['reservations.view', 'reservations.manage', 'guests.view']);

    $this->actingAs($user)
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['guests' => [['stays_in' => 0, ...$tab, 'guest_id' => $tab['guest_id'] ?? null]]]))
        ->assertSessionHasErrors([$key => $message]);

    expect(Reservation::query()->count())->toBe(0)->and(Guest::query()->count())->toBe(1);

    $this->get(bookingFormUrl($setup, 'reservations.new'))->assertInertia(fn (Assert $page) => $page->where('canManageGuests', false)->where('countries', []));
})->with([
    'a new guest' => [['full_name' => 'New Person'], 'guests.0.full_name', 'Pick an existing guest.'],
    'a profile update' => [['guest_id' => 1, 'update_profile' => true], 'guests.0.update_profile', 'You may not change a guest\'s profile.'],
]);

test('staff without guests.manage can book with an existing guest', function () {
    $setup = bookingSetup();
    $user = User::factory()->create(['property_id' => $setup['property']->id]);
    actionsRoleWith($user, ['reservations.view', 'reservations.manage', 'guests.view']);

    $this->actingAs($user)->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['guests' => [['guest_id' => $setup['guest']->id, 'stays_in' => 0]]]))
        ->assertSessionHasNoErrors();

    expect(Reservation::query()->count())->toBe(1);
});

test('a refused guest tab shows the error on the tab and saves nothing, not even the new guest', function (string $case) {
    $setup = bookingSetup();
    $bed = ['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 1];
    [$body, $key, $message] = match ($case) {
        'a second guest in a bed unit' => [bookingBody($setup, ['lines' => [$bed], 'guests' => [['full_name' => 'First', 'nationality_code' => 'MY', 'stays_in' => 0], ['full_name' => 'Second', 'nationality_code' => 'MY', 'stays_in' => 0]]]), 'guests.1.stays_in', 'A bed unit holds one guest.'],
        'a unit that is not on the booking' => [bookingBody($setup, ['guests' => [['full_name' => 'First', 'nationality_code' => 'MY', 'stays_in' => 5]]]), 'guests.0.stays_in', 'This unit is not on the booking.'],
        'a guest tab with no unit' => [bookingBody($setup, ['guests' => [['full_name' => 'First', 'stays_in' => 0], ['full_name' => 'Second']]]), 'guests.1.stays_in', 'Choose the unit this guest stays in.'],
        'a new guest with no name' => [bookingBody($setup, ['guests' => [['email' => 'a@example.com', 'nationality_code' => 'MY']]]), 'guests.0.full_name', 'Enter the guest\'s name or pick an existing guest.'],
        'the same guest twice in a unit' => [bookingBody($setup, ['guests' => [['guest_id' => $setup['guest']->id, 'stays_in' => 0], ['guest_id' => $setup['guest']->id, 'stays_in' => 0]]]), 'guests.1.guest_id', 'This guest already stays in this unit.'],
        'a guest of another property' => [bookingBody($setup, ['guests' => [['guest_id' => $setup['guest']->id], ['guest_id' => Guest::factory()->create()->id, 'stays_in' => 0]]]), 'guests.1.guest_id', 'This guest cannot be found.'],
    };
    $before = Guest::query()->count();

    $this->actingAs($setup['user'])->post(bookingFormUrl($setup, 'reservations.store'), $body)->assertSessionHasErrors([$key => $message]);

    expect(Reservation::query()->count())->toBe(0)->and(Guest::query()->count())->toBe($before);
})->with(['a second guest in a bed unit', 'a unit that is not on the booking', 'a guest tab with no unit', 'a new guest with no name', 'the same guest twice in a unit', 'a guest of another property']);

test('a guest tab checks its profile fields', function (array $tab, string $field) {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['guests' => [['full_name' => 'First', 'stays_in' => 0, ...$tab]]]))
        ->assertSessionHasErrors($field);

    expect(Reservation::query()->count())->toBe(0);
})->with([
    'a bad phone' => [['phone' => 'abc'], 'guests.0.phone'],
    'a bad email' => [['email' => 'not-an-email'], 'guests.0.email'],
    'a date of birth in the future' => [['date_of_birth' => '2999-01-01'], 'guests.0.date_of_birth'],
    'an unknown country' => [['nationality_code' => 'ZZ'], 'guests.0.nationality_code'],
]);

test('the booking saves the expected times of the stay on every unit', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['arrival_time' => '18:30', 'departure_time' => '09:15']))
        ->assertSessionHasNoErrors();

    $unit = Reservation::query()->firstOrFail()->units()->sole();

    expect(substr($unit->arrival_time, 0, 5))->toBe('18:30')
        ->and(substr($unit->departure_time, 0, 5))->toBe('09:15');
});

test('a booking without times uses the property times', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup))
        ->assertSessionHasNoErrors();

    $unit = Reservation::query()->firstOrFail()->units()->sole();

    expect(substr($unit->arrival_time, 0, 5))->toBe('15:00')
        ->and(substr($unit->departure_time, 0, 5))->toBe('11:00');
});

test('a time that is not HH:MM is refused', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['arrival_time' => '25:00', 'departure_time' => 'noon']))
        ->assertSessionHasErrors(['arrival_time', 'departure_time']);

    expect(Reservation::query()->count())->toBe(0);
});

test('a mode switch gets the walk-in props in a partial request', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->get(bookingFormUrl($setup, 'reservations.new'))
        ->assertOk();

    $this->actingAs($setup['user'])
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => inertia()->getVersion(),
            'X-Inertia-Partial-Component' => 'reservations/create',
            'X-Inertia-Partial-Data' => 'isWalkIn,sources,nowTime',
        ])
        ->get(bookingFormUrl($setup, 'reservations.walk-in'))
        ->assertOk()
        ->assertJsonPath('props.isWalkIn', true)
        ->assertJsonPath('props.sources.0.type', 'walk_in')
        ->assertJsonPath('props.nowTime', fn (string $time) => preg_match('/^\d{2}:\d{2}$/', $time) === 1)
        ->assertJsonMissingPath('props.addUnitOptions');
});

test('the booking page lists the active companies of the property for staff who may view them', function () {
    $setup = bookingSetup();
    Company::factory()->for($setup['property'])->create(['name' => 'Borneo Travel']);
    Company::factory()->for($setup['property'])->create(['name' => 'Acme']);
    Company::factory()->for($setup['property'])->suspended()->create();
    Company::factory()->create();

    $this->actingAs($setup['user'])
        ->get(bookingFormUrl($setup, 'reservations.new'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies', 2)
            ->where('companies.0.name', 'Acme')
            ->where('companies.1.name', 'Borneo Travel'));
});

test('a booking can name a company and a refused company saves nothing', function () {
    $setup = bookingSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $suspended = Company::factory()->for($setup['property'])->suspended()->create();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['company_id' => $suspended->id]))
        ->assertSessionHasErrors('company_id');

    expect(Reservation::query()->count())->toBe(0);

    $this->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['company_id' => $company->id]));

    expect(Reservation::query()->sole()->company_id)->toBe($company->id);
});

test('staff without company.view cannot name a company', function () {
    $setup = bookingSetup();
    $company = Company::factory()->for($setup['property'])->create();
    actionsRoleWith($setup['user'], ['reservations.view', 'reservations.manage', 'guests.view']);

    $this->actingAs($setup['user'])
        ->get(bookingFormUrl($setup, 'reservations.new'))
        ->assertInertia(fn (Assert $page) => $page->where('companies', []));

    $this->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['company_id' => $company->id]));

    expect(Reservation::query()->sole()->company_id)->toBeNull();
});

test('removed auto-add items are logged on the reservation and the kept ones are ordinary add-ons', function () {
    $setup = bookingSetup();
    $kept = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Cleaning fee', 'default_amount' => '20.00', 'charging_unit' => 'per_stay', 'auto_add' => true]);
    $declined = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Resort fee', 'default_amount' => '5.00', 'charging_unit' => 'per_night', 'auto_add' => true]);
    $notAuto = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '5.00', 'auto_add' => false]);

    $this->actingAs($setup['user'])->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, [
        'lines' => [bookingFormLine($setup, [
            'add_ons' => [['charge_item_id' => $kept->id]],
            'declined_auto_add_ons' => [$declined->id, $notAuto->id, 999999],
        ])],
    ]))->assertSessionHasNoErrors();

    $reservation = Reservation::query()->sole();
    $logs = Activity::query()->where('event', 'reservation.auto_add_on_declined')->get();

    expect($reservation->units()->sole()->addOns()->pluck('charge_item_id')->all())->toBe([$kept->id])
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]->properties['charge_item_id'])->toBe($declined->id)
        ->and($logs[0]->subject_id)->toBe($reservation->id);
});

test('the booking form options say which charge items are added automatically', function () {
    $setup = bookingSetup();
    ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '20.00', 'auto_add' => true]);

    $this->actingAs($setup['user'])
        ->get(bookingFormUrl($setup, 'reservations.new'))
        ->assertInertia(fn (Assert $page) => $page->where('chargeItems.0.autoAdd', true));
});

test('an OTA booking with its booking number saves on the OTA source', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['booking_source_id' => $setup['ota']->id, 'external_reference' => 'BK-12345']))
        ->assertSessionHasNoErrors();

    $reservation = Reservation::query()->sole();

    expect($reservation->booking_source_id)->toBe($setup['ota']->id)
        ->and($reservation->external_reference)->toBe('BK-12345');
});

test('the booking source errors say what is missing', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['booking_source_id' => $setup['ota']->id]))
        ->assertSessionHasErrors(['external_reference' => 'Enter the OTA booking number.']);

    $this->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['booking_source_id' => $setup['companySource']->id]))
        ->assertSessionHasErrors(['company_id' => 'Select the company.']);

    $this->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['booking_source_id' => null]))
        ->assertSessionHasErrors(['booking_source_id' => 'Pick a booking source.']);

    expect(Reservation::query()->count())->toBe(0);
});

test('a Company / Travel agent booking saves with its company and needs company.view', function () {
    $setup = bookingSetup();
    $company = Company::factory()->for($setup['property'])->create();

    $this->actingAs($setup['user'])
        ->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['booking_source_id' => $setup['companySource']->id, 'company_id' => $company->id]))
        ->assertSessionHasNoErrors();

    expect(Reservation::query()->sole()->booking_source_id)->toBe($setup['companySource']->id);

    actionsRoleWith($setup['user'], ['reservations.view', 'reservations.manage', 'guests.view']);

    $this->post(bookingFormUrl($setup, 'reservations.store'), bookingBody($setup, ['booking_source_id' => $setup['companySource']->id, 'company_id' => $company->id]))
        ->assertSessionHasErrors(['booking_source_id' => 'This booking source is not available. Pick another.']);

    expect(Reservation::query()->count())->toBe(1);
});

test('the Source select offers the Company source only to staff with company.view and an Active company', function () {
    $setup = bookingSetup();
    $offered = fn () => collect($this->actingAs($setup['user'])->get(bookingFormUrl($setup, 'reservations.new'))->original->getData()['page']['props']['sources'])->pluck('name')->all();

    expect($offered())->toBe(['Phone', 'Booking.com']);

    Company::factory()->for($setup['property'])->create();
    BookingSource::factory()->inactive()->create(['property_id' => $setup['property']->id, 'name' => 'Old desk']);

    expect($offered())->toBe(['Phone', 'Booking.com', 'Company / Travel agent']);

    actionsRoleWith($setup['user'], ['reservations.view', 'reservations.manage', 'guests.view']);

    expect($offered())->toBe(['Phone', 'Booking.com']);
});
