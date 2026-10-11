<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReverseLine;
use App\Data\Folio\NewChargeData;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date, an owner, a reservation with one checked-in unit that already has a
 * Unit folio, and the room revenue code.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, folio: Folio, room: TransactionCode, item: ChargeItem}
 */
function faSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);

    return [
        'user' => $user,
        'property' => $property,
        'reservation' => $reservation,
        'unit' => $unit,
        'folio' => app(CreateFolio::class)($reservation, $unit),
        'room' => TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]),
        'item' => ChargeItem::factory()->create(['property_id' => $property->id, 'default_amount' => null]),
    ];
}

function faUrl(array $setup, string $route, Folio $folio, array $extra = []): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $folio->id, ...$extra]);
}

/** @return array<string, mixed> */
function faCharge(array $setup, array $overrides = []): array
{
    return [
        'folio_id' => $setup['folio']->id,
        'charge_item_id' => $setup['item']->id,
        'quantity' => 1,
        'unit_amount' => '40.00',
        'idempotency_key' => 'manual:'.fake()->uuid(),
        ...$overrides,
    ];
}

function faPost(array $setup, ReservationUnit $unit, string $amount = '40.00'): FolioLine
{
    return app(PostCharge::class)($setup['user'], $unit, NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $setup['room']->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]))->line();
}

function faStaff(array $setup, DefaultRoleEnum $role): User
{
    return User::factory()->withRole($role)->create(['property_id' => $setup['property']->id]);
}

test('posting a charge on a unit folio posts for its unit and names the folio in the toast', function () {
    $setup = faSetup();

    $this->actingAs($setup['user'])
        ->from('/back')
        ->post(faUrl($setup, 'reservations.folios.charges.store', $setup['folio']), faCharge($setup))
        ->assertRedirect('/back')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Charge posted to folio '.$setup['folio']->number.'.']);

    expect($setup['folio']->lines()->sum('amount'))->toEqual(40)
        ->and(Activity::query()->where('event', 'folio.charge_posted')->count())->toBe(1);
});

test('a description replaces the item name on the line', function () {
    $setup = faSetup();
    $url = faUrl($setup, 'reservations.folios.charges.store', $setup['folio']);

    $this->actingAs($setup['user'])->post($url, faCharge($setup, ['description' => 'Broken glass']))->assertSessionHasNoErrors();
    $this->actingAs($setup['user'])->post($url, faCharge($setup))->assertSessionHasNoErrors();

    expect($setup['folio']->lines()->orderBy('id')->pluck('description')->all())->toBe(['Broken glass', $setup['item']->name]);
});

test('the same idempotency key posts once', function () {
    $setup = faSetup();
    $payload = faCharge($setup);
    $url = faUrl($setup, 'reservations.folios.charges.store', $setup['folio']);

    $this->actingAs($setup['user'])->post($url, $payload);
    $this->actingAs($setup['user'])->post($url, $payload);

    expect($setup['folio']->lines()->count())->toBe(1);
});

test('a master folio needs the unit to post for and the charge lands on the chosen folio', function () {
    $setup = faSetup();
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $url = faUrl($setup, 'reservations.folios.charges.store', $master);

    $this->actingAs($setup['user'])->post($url, faCharge($setup, ['folio_id' => $master->id]))->assertSessionHasErrors('unit_id');

    $this->actingAs($setup['user'])->post($url, faCharge($setup, ['folio_id' => $master->id, 'unit_id' => $setup['unit']->id]))->assertSessionHasNoErrors();

    expect($master->lines()->count())->toBe(1)
        ->and($setup['folio']->lines()->count())->toBe(0);
});

test('a charge refusal comes back as an error on its field and writes nothing', function (array $payload, string $field) {
    $setup = faSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '25.00', 'allow_override' => false]);
    $url = faUrl($setup, 'reservations.folios.charges.store', $setup['folio']);
    $body = match ($field) {
        'unit_amount' => faCharge($setup, ['charge_item_id' => $item->id, 'unit_amount' => '30.00']),
        default => faCharge($setup, $payload),
    };

    $this->actingAs($setup['user'])->post($url, $body)->assertSessionHasErrors($field);

    expect(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'folio.charge_posted')->count())->toBe(0);
})->with([
    'bad amount' => [['unit_amount' => '0.00'], 'unit_amount'],
    'price not changeable' => [[], 'unit_amount'],
    'bad quantity' => [['quantity' => 1000], 'quantity'],
    'no charge' => [['charge_item_id' => null], 'charge_item_id'],
]);

test('a unit that is not checked in refuses the charge', function () {
    $setup = faSetup();
    $setup['unit']->forceFill(['status' => 'confirmed'])->save();

    $this->actingAs($setup['user'])
        ->post(faUrl($setup, 'reservations.folios.charges.store', $setup['folio']), faCharge($setup))
        ->assertSessionHasErrors('unit_id');
});

test('a closed folio refuses the charge', function () {
    $setup = faSetup();
    app(CloseFolio::class)($setup['user'], $setup['folio']);

    $this->actingAs($setup['user'])
        ->post(faUrl($setup, 'reservations.folios.charges.store', $setup['folio']), faCharge($setup))
        ->assertSessionHasErrors('form');
});

test('reversing a charge posts the reversal and a second try is an error toast', function () {
    $setup = faSetup();
    Tax::factory()->for($setup['property'])->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false])->applicableTransactionCodes()->attach($setup['room']);
    $charge = faPost($setup, $setup['unit'], '100.00');
    $url = faUrl($setup, 'reservations.folios.lines.reverse', $setup['folio'], ['line' => $charge->id]);

    $this->actingAs($setup['user'])->post($url)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Line reversed.']);

    expect($setup['folio']->lines()->sum('amount'))->toEqual(0)
        ->and($setup['folio']->lines()->count())->toBe(4);

    $this->actingAs($setup['user'])->post($url)->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This line is already reversed.']);

    expect($setup['folio']->lines()->count())->toBe(4)
        ->and(Activity::query()->where('event', 'folio.line_reversed')->count())->toBe(1);
});

test('transferring a charge moves it with its children to another folio of the reservation', function () {
    $setup = faSetup();
    Tax::factory()->for($setup['property'])->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false])->applicableTransactionCodes()->attach($setup['room']);
    $charge = faPost($setup, $setup['unit'], '100.00');
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])
        ->post(faUrl($setup, 'reservations.folios.transfers.store', $setup['folio']), ['line_ids' => [$charge->id], 'target_folio_id' => $master->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Moved to folio '.$master->number.'.']);

    expect($master->lines()->sum('amount'))->toEqual(108)
        ->and($setup['folio']->lines()->sum('amount'))->toEqual(0);
});

test('a transfer to a closed folio or the same folio is refused on the target field', function () {
    $setup = faSetup();
    $charge = faPost($setup, $setup['unit']);
    $closed = Folio::factory()->master()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $url = faUrl($setup, 'reservations.folios.transfers.store', $setup['folio']);

    $this->actingAs($setup['user'])->post($url, ['line_ids' => [$charge->id], 'target_folio_id' => $setup['folio']->id])->assertSessionHasErrors('target_folio_id');
    $this->actingAs($setup['user'])->post($url, ['line_ids' => [$charge->id], 'target_folio_id' => $closed->id])->assertSessionHasErrors();

    expect($setup['folio']->lines()->count())->toBe(1);
});

test('the actions need their permission and stay inside the property and the reservation', function () {
    $setup = faSetup();
    $charge = faPost($setup, $setup['unit']);
    $frontDesk = faStaff($setup, DefaultRoleEnum::FrontDesk);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $this->actingAs($frontDesk)->post(faUrl($setup, 'reservations.folios.lines.reverse', $setup['folio'], ['line' => $charge->id]))->assertForbidden();
    $this->actingAs($frontDesk)->post(faUrl($setup, 'reservations.folios.transfers.store', $setup['folio']), ['line_ids' => [$charge->id], 'target_folio_id' => $master->id])->assertForbidden();
    $this->actingAs($frontDesk)->post(faUrl($setup, 'reservations.folios.charges.store', $setup['folio']), faCharge($setup))->assertSessionHasNoErrors();

    $otherReservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $foreignFolio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $otherReservation->id]);

    $this->actingAs($setup['user'])->post(faUrl($setup, 'reservations.folios.charges.store', $foreignFolio), faCharge($setup))->assertNotFound();
    $this->actingAs($setup['user'])->post(faUrl($setup, 'reservations.folios.lines.reverse', $setup['folio'], ['line' => faPost($setup, ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $otherReservation->id]))->id]))->assertNotFound();
    $this->actingAs($setup['user'])->post(faUrl($setup, 'reservations.folios.transfers.store', $setup['folio']), ['line_ids' => [$charge->id], 'target_folio_id' => $foreignFolio->id])->assertNotFound();

    expect(FolioLine::query()->where('reversal_of_id', '!=', null)->count())->toBe(0);
});

test('rows carry only the actions the user may take on them', function () {
    $setup = faSetup();
    Tax::factory()->for($setup['property'])->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false])->applicableTransactionCodes()->attach($setup['room']);
    $charge = faPost($setup, $setup['unit'], '100.00');
    $reversed = faPost($setup, $setup['unit'], '10.00');
    app(ReverseLine::class)($setup['user'], $reversed);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canPostCharge', true)
        ->where('folio.postUnits.0.id', $setup['unit']->id)
        ->where('folio.transferTargets.0.id', $master->id)
        ->where('folio.postFolios', fn ($folios) => collect($folios)->pluck('id')->sort()->values()->all() === collect([$setup['folio']->id, $master->id])->sort()->values()->all())
        ->where('folio.postRooms.0.id', $setup['unit']->id)
        ->where('folio.rows', fn ($rows) => collect($rows)->map(fn ($row) => [$row['lineId'] === $charge->id, $row['actions']])->take(2)->all() === [
            [true, ['reverse', 'transfer']],
            [false, []],
        ])
        ->where('folio.rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['tags'] === [] || $row['actions'] === [])));

    $this->actingAs(faStaff($setup, DefaultRoleEnum::FrontDesk))->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canPostCharge', true)
        ->where('folio.transferTargets', [])
        ->where('folio.rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['actions'] === [])));

    app(ReverseLine::class)($setup['user'], $charge);
    app(CloseFolio::class)($setup['user'], $setup['folio']);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canPostCharge', false)
        ->where('folio.transferTargets', [])
        ->where('folio.rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['actions'] === [])));
});

test('the charge options load on request only', function () {
    $setup = faSetup();
    $code = TransactionCode::factory()->create(['property_id' => $setup['property']->id, 'kind' => 'charge', 'active' => true]);
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page->missing('chargeCodes'));

    $this->actingAs($setup['user'])
        ->get($url, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'chargeCodes', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])
        ->assertOk()
        ->assertJsonPath('props.chargeCodes.0.id', fn ($id) => is_int($id));

    expect($code->id)->toBeInt();
});
