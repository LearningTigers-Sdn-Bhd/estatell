<?php

use App\Data\Guests\GuestStayData;
use App\Data\Guests\GuestStaySummaryData;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Guests\GuestStayHistory;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A unit of a new reservation of the property with the guest on it.
 *
 * @param  array<string, mixed>  $attributes
 */
function stayFor(Guest $guest, array $attributes = [], bool $primary = true, ?Reservation $reservation = null): ReservationUnit
{
    $status = $attributes['status'] ?? UnitStatus::CheckedOut;
    $unit = ReservationUnit::factory()->create([
        'reservation_id' => $reservation->id ?? Reservation::factory()->create(['property_id' => $guest->property_id])->id,
        'status' => $status,
        'checked_in_at' => in_array($status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true) ? now()->subDay() : null,
        'checked_out_at' => $status === UnitStatus::CheckedOut ? now() : null,
        ...$attributes,
    ]);
    UnitGuest::factory()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $guest->id, 'is_primary' => $primary]);

    return $unit;
}

function unitFolio(ReservationUnit $unit): Folio
{
    return Folio::factory()->forUnit($unit)->create(['property_id' => $unit->property_id, 'reservation_id' => $unit->reservation_id]);
}

function guestSummary(Guest $guest): GuestStaySummaryData
{
    return GuestStaySummaryData::fromStays(app(GuestStayHistory::class)->stays($guest), CarbonImmutable::parse('2025-10-14'));
}

/**
 * @return array<string, mixed>
 */
function listFigures(Guest $guest): array
{
    return (array) app(GuestStayHistory::class)
        ->figures(Property::query()->findOrFail($guest->property_id), '2025-10-14')
        ->where('unit_guests.guest_id', $guest->id)
        ->first();
}

test('only checked-in and checked-out units are stays, with their nights, day uses and dates', function () {
    $guest = Guest::factory()->create();
    stayFor($guest, ['arrival_date' => '2024-03-01', 'departure_date' => '2024-03-04']);
    stayFor($guest, ['arrival_date' => '2026-09-01', 'departure_date' => '2026-09-03']);
    ReservationUnit::factory()->dayUse('2026-09-20')->checkedOut()->create(['reservation_id' => Reservation::factory()->create(['property_id' => $guest->property_id])->id])
        ->guests()->create(['guest_id' => $guest->id, 'is_primary' => true]);
    stayFor($guest, ['arrival_date' => '2026-10-13', 'departure_date' => '2026-10-16', 'status' => UnitStatus::CheckedIn]);

    foreach ([UnitStatus::Confirmed, UnitStatus::Cancelled, UnitStatus::NoShow] as $status) {
        stayFor($guest, ['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-25', 'status' => $status]);
    }

    $summary = guestSummary($guest);

    expect($summary->stays)->toBe(4)
        ->and($summary->staysLastYear)->toBe(3)
        ->and($summary->nights)->toBe(8)
        ->and($summary->dayUses)->toBe(1)
        ->and($summary->lastVisit)->toBe('2026-09-20')
        ->and($summary->inHouse)->toBeTrue();

    expect(listFigures($guest))->toMatchArray([
        'stays' => 4,
        'stays_last_year' => 3,
        'nights' => 8,
        'day_uses' => 1,
        'last_visit' => '2026-09-20',
        'latest_departure' => '2026-10-16',
        'in_house' => true,
    ]);
});

test('spend counts charges and discounts after reversals, without tax, payments or the opening balance', function () {
    $guest = Guest::factory()->create();
    $unit = stayFor($guest);
    $folio = unitFolio($unit);

    $room = FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '300.00']);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'parent_line_id' => $room->id, 'kind' => TransactionKind::Adjustment, 'amount' => '-30.00']);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'parent_line_id' => $room->id, 'kind' => TransactionKind::Tax, 'amount' => '24.00']);
    FolioLine::factory()->payment('294.00')->create(['folio_id' => $folio->id]);
    $minibar = FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '50.00']);
    FolioLine::factory()->reversalOf($minibar)->create();
    FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'kind' => TransactionKind::Adjustment,
        'amount' => '500.00',
        'transaction_code_id' => TransactionCode::factory()->system(SystemTransactionCode::OpeningBalance)->create(['property_id' => $guest->property_id])->id,
    ]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '80.00', 'migrated' => true]);

    expect(guestSummary($guest)->spend)->toBe('350.00')
        ->and((string) listFigures($guest)['spend'])->toBe('350.00');
});

test('a line on the master folio counts on the unit of its source, else on the first stay', function () {
    $guest = Guest::factory()->create();
    $other = Guest::factory()->create(['property_id' => $guest->property_id]);
    $reservation = Reservation::factory()->create(['property_id' => $guest->property_id]);
    $first = stayFor($other, reservation: $reservation);
    $second = stayFor($guest, reservation: $reservation);
    $master = Folio::factory()->master()->create(['property_id' => $guest->property_id, 'reservation_id' => $reservation->id]);
    $night = UnitNight::factory()->create(['reservation_unit_id' => $second->id]);

    $room = FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '200.00', 'source_type' => $night->getMorphClass(), 'source_id' => $night->id]);
    FolioLine::factory()->create(['folio_id' => $master->id, 'parent_line_id' => $room->id, 'kind' => TransactionKind::Adjustment, 'amount' => '-20.00']);
    FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '40.00']);

    expect(guestSummary($guest)->spend)->toBe('180.00')
        ->and(guestSummary($other)->spend)->toBe('40.00')
        ->and($first->id)->toBeLessThan($second->id);
});

test('spend goes to the primary guest only, and the other occupant sees the stay without spend', function () {
    $primary = Guest::factory()->create();
    $sharer = Guest::factory()->create(['property_id' => $primary->property_id]);
    $unit = stayFor($primary);
    UnitGuest::factory()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $sharer->id]);
    FolioLine::factory()->create(['folio_id' => unitFolio($unit)->id, 'amount' => '120.00']);

    $sharerStays = app(GuestStayHistory::class)->stays($sharer);

    expect(guestSummary($primary)->spend)->toBe('120.00')
        ->and(guestSummary($sharer)->spend)->toBe('0.00')
        ->and(guestSummary($sharer)->stays)->toBe(1)
        ->and(GuestStayData::fromUnit($sharerStays->sole())->spend)->toBeNull()
        ->and((string) listFigures($sharer)['spend'])->toBe('0.00');
});

test('charges of a cancelled unit are not spend', function () {
    $guest = Guest::factory()->create();
    $unit = stayFor($guest, ['status' => UnitStatus::Cancelled]);
    FolioLine::factory()->create(['folio_id' => unitFolio($unit)->id, 'amount' => '90.00']);
    stayFor($guest);

    expect(guestSummary($guest)->spend)->toBe('0.00')
        ->and((string) listFigures($guest)['spend'])->toBe('0.00');
});

test('the source is the latest stay company, else its booking source', function () {
    $guest = Guest::factory()->create();
    $company = Company::factory()->create(['property_id' => $guest->property_id, 'name' => 'Sandakan Travel']);
    stayFor($guest, ['arrival_date' => '2026-01-02', 'departure_date' => '2026-01-04'], reservation: Reservation::factory()->ota('Agoda')->create(['property_id' => $guest->property_id]));

    expect(guestSummary($guest)->source)->toBe('Agoda')
        ->and(guestSummary($guest)->sourceKind)->toBe('Booking source');

    stayFor($guest, ['arrival_date' => '2026-05-02', 'departure_date' => '2026-05-04'], reservation: Reservation::factory()->company($company)->create(['property_id' => $guest->property_id]));

    expect(guestSummary($guest)->source)->toBe('Sandakan Travel')
        ->and(guestSummary($guest)->sourceKind)->toBe($company->type->label());
});

test('lines and stays of another property are not counted', function () {
    $guest = Guest::factory()->create();
    $unit = stayFor($guest);
    FolioLine::factory()->create(['folio_id' => unitFolio($unit)->id, 'amount' => '100.00']);

    $elsewhere = Property::factory()->create();

    expect(app(GuestStayHistory::class)->figures($elsewhere, '2025-10-14')->get())->toBeEmpty()
        ->and(app(GuestStayHistory::class)->unitSpend($elsewhere->id)->get())->toBeEmpty();
});

test('the Stays tab lists the stays and every tab shows the figures', function () {
    $owner = User::factory()->owner()->create();
    $guest = Guest::factory()->create(['property_id' => $owner->property_id]);
    $unit = stayFor($guest, ['arrival_date' => '2026-09-01', 'departure_date' => '2026-09-04']);
    FolioLine::factory()->create(['folio_id' => unitFolio($unit)->id, 'amount' => '450.00']);

    $this->actingAs($owner)
        ->get(route('guests.stays.index', [$owner->property->code, $guest]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('guests/show')
            ->where('tab', 'stays')
            ->where('staySummary.stays', 1)
            ->where('staySummary.nights', 3)
            ->where('staySummary.spend', '450.00')
            ->where('stays.0.shortCode', $unit->reservation->short_code)
            ->where('stays.0.nights', 3)
            ->where('stays.0.spend', '450.00'));

    $this->get(route('guests.notes.index', [$owner->property->code, $guest]))
        ->assertInertia(fn (Assert $page) => $page->where('staySummary.stays', 1)->missing('stays'));
});

test('the Stays tab needs guests.view and the guest of the property', function () {
    $housekeeping = User::factory()->withPermissions([])->create();
    $guest = Guest::factory()->create(['property_id' => $housekeeping->property_id]);

    $this->actingAs($housekeeping)
        ->get(route('guests.stays.index', [$housekeeping->property->code, $guest]))
        ->assertForbidden();

    $viewer = User::factory()->for($housekeeping->property)->withPermissions([PermissionEnum::GuestsView])->create();
    $foreign = Guest::factory()->create();

    $this->actingAs($viewer)
        ->get(route('guests.stays.index', [$viewer->property->code, $foreign]))
        ->assertNotFound();
});

test('the guest list shows the stay figures and sorts by spend with guests without stays last', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $big = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Big Spender']);
    $small = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Small Spender']);
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aaron New']);

    foreach ([[$big, '900.00'], [$small, '100.00']] as [$guest, $amount]) {
        $unit = stayFor($guest, reservation: Reservation::factory()->ota('Agoda')->create(['property_id' => $property->id]));
        FolioLine::factory()->create(['folio_id' => unitFolio($unit)->id, 'amount' => $amount]);
    }

    $this->actingAs($owner)
        ->get(route('guests.index', [$property->code, 'sort' => '-spend']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('guests.data.0.fullName', 'Big Spender')
            ->where('guests.data.0.stays', 1)
            ->where('guests.data.0.spend', '900.00')
            ->where('guests.data.0.lastVisit', '2026-11-05')
            ->where('guests.data.0.source', 'Agoda')
            ->where('guests.data.1.fullName', 'Small Spender')
            ->where('guests.data.2.fullName', 'Aaron New')
            ->where('guests.data.2.stays', 0)
            ->where('guests.data.2.spend', null));

    $this->get(route('guests.index', [$property->code, 'sort' => 'spend']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('guests.data.0.fullName', 'Small Spender')
            ->where('guests.data.2.fullName', 'Aaron New'));
});

test('the guest list filters by source, returning and missing contact', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $agoda = Reservation::factory()->ota('Agoda')->create(['property_id' => $property->id]);
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Returning Guest', 'email' => null]);
    stayFor($returning, reservation: $agoda);
    stayFor($returning);
    $first = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'First Guest', 'phone' => null, 'email' => 'first@example.com']);
    stayFor($first);

    $names = fn (array $filter): array => collect($this->actingAs($owner)->get(route('guests.index', [$property->code, 'filter' => $filter]))
        ->viewData('page')['props']['guests']['data'])->pluck('fullName')->sort()->values()->all();

    expect($names(['returning' => 'returning']))->toBe(['Returning Guest'])
        ->and($names(['returning' => 'first']))->toBe(['First Guest'])
        ->and($names(['source' => (string) $agoda->booking_source_id]))->toBe(['Returning Guest'])
        ->and($names(['contact' => 'no_email']))->toBe(['Returning Guest'])
        ->and($names(['contact' => 'no_phone']))->toBe(['First Guest']);
});

test('the guest list sorts by email, phone and nationality name with empty values last', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Swiss', 'email' => 'b@example.com', 'phone' => '+60123000002', 'nationality_code' => 'CH']);
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Chinese', 'email' => 'A@example.com', 'phone' => '+60123000001', 'nationality_code' => 'CN']);
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Unknown', 'email' => null, 'phone' => null, 'nationality_code' => null]);

    $names = fn (string $sort): array => collect($this->actingAs($owner)->get(route('guests.index', [$property->code, 'sort' => $sort]))
        ->viewData('page')['props']['guests']['data'])->pluck('fullName')->all();

    expect($names('email'))->toBe(['Chinese', 'Swiss', 'Unknown'])
        ->and($names('-email'))->toBe(['Swiss', 'Chinese', 'Unknown'])
        ->and($names('phone'))->toBe(['Chinese', 'Swiss', 'Unknown'])
        ->and($names('nationality'))->toBe(['Chinese', 'Swiss', 'Unknown'])
        ->and($names('-nationality'))->toBe(['Swiss', 'Chinese', 'Unknown']);
});
