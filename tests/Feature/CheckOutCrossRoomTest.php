<?php

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CloseSettledUnitFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner of a live property with an open business date (2026-10-10) and the room revenue code. Room 101 is CheckedIn
 * and departs today. Room 102 is CheckedIn from today to `$departure`, with unposted nights of 100.00, and a Manual
 * rule sends its Room charges to Room 101's folio. Each unit has its Unit folio. No tax.
 *
 * @return array{user: User, property: Property, reservation: Reservation, room: TransactionCode, out: ReservationUnit, stays: ReservationUnit, outFolio: Folio, staysFolio: Folio, rule: FolioRoutingRule}
 */
function xrSetup(string $departure = '2026-10-12'): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $code = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $roomType = RoomType::factory()->for($property)->create();
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $units = [];

    foreach ([['101', '2026-10-09', '2026-10-10'], ['102', '2026-10-10', $departure]] as [$number, $arrival, $leaves]) {
        $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => $number]);
        $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create(['room_type_id' => $roomType->id, 'arrival_date' => $arrival, 'departure_date' => $leaves]);
        $unit->nights()->update(['room_id' => $room->id, 'base_amount' => '100.00', 'amount' => '100.00']);
        $units[] = $unit;
    }

    $outFolio = app(CreateFolio::class)($reservation, $units[0]);
    $staysFolio = app(CreateFolio::class)($reservation, $units[1]);
    $rule = FolioRoutingRule::factory()->forUnit($units[1])->forCategory(TransactionCategory::Room)->toFolio($outFolio)->create(['reservation_id' => $reservation->id]);

    return [
        'user' => $user,
        'property' => $property,
        'reservation' => $reservation,
        'room' => $code,
        'out' => $units[0],
        'stays' => $units[1],
        'outFolio' => $outFolio,
        'staysFolio' => $staysFolio,
        'rule' => $rule,
    ];
}

/** A posted line on a folio for a unit night (a room charge) or with no source (a payment when negative). */
function xrLine(array $setup, Folio $folio, string $amount, ?UnitNight $night = null, ?FolioLine $parent = null): FolioLine
{
    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $setup['room']->id,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => '2026-10-10',
        'source_type' => $night?->getMorphClass(),
        'source_id' => $night?->id,
        'parent_line_id' => $parent?->id,
        'idempotency_key' => $night !== null && $parent === null ? 'night:'.$night->id : 'manual:'.fake()->uuid(),
    ]);
}

function xrCheckOut(array $setup): UnitStayResult
{
    return app(CheckOutUnit::class)($setup['user'], $setup['out']);
}

function xrUrl(array $setup, string $route, array $extra = []): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, ...$extra]);
}

test('a unit whose folio receives another room\'s unposted nights, tonight\'s included, cannot check out', function (string $departure, string $rooms) {
    $setup = xrSetup($departure);

    $result = xrCheckOut($setup);

    expect($result->refusal)->toBe(UnitStayResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES)
        ->and($result->rooms)->toBe([$rooms])
        ->and($setup['out']->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and($setup['outFolio']->fresh()->status)->toBe(FolioStatus::Open)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'tonight only' => ['2026-10-11', 'Room 102 (10 Oct)'],
    'two nights' => ['2026-10-12', 'Room 102 (10–11 Oct)'],
]);

test('the check-out route names the room and its dates', function () {
    $setup = xrSetup();

    $this->actingAs($setup['user'])
        ->post(xrUrl($setup, 'reservations.units.check-out', ['unit' => $setup['out']->id]))
        ->assertSessionHasErrors(['form' => 'Room 102 (10–11 Oct) still charges its scheduled nights to this folio. Send them to another folio first.']);
});

test('check-out passes once the rule changes or the last night is posted', function (string $case) {
    $setup = xrSetup();

    if ($case === 'rule removed') {
        $setup['rule']->delete();
    } else {
        foreach ($setup['stays']->nights as $night) {
            xrLine($setup, $setup['outFolio'], '100.00', $night);
        }

        xrLine($setup, $setup['outFolio'], '-200.00');
    }

    expect(xrCheckOut($setup)->isRefused())->toBeFalse()
        ->and($setup['outFolio']->fresh()->status)->toBe(FolioStatus::Closed);
})->with(['rule removed', 'nights posted']);

test('closing or settling a folio that receives another room\'s nights is refused before any write', function () {
    $setup = xrSetup();
    $setup['out']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();

    $closed = app(CloseFolio::class)($setup['user'], $setup['outFolio']);

    expect($closed->refusal)->toBe(FolioStatusResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES)
        ->and($closed->rooms)->toBe(['Room 102 (10–11 Oct)']);

    app(CloseSettledUnitFolio::class)($setup['user'], $setup['outFolio']);

    expect($setup['outFolio']->fresh()->status)->toBe(FolioStatus::Open);

    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $setup['property']->id]);
    $company = Company::factory()->for($setup['property'])->create();
    $party = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $company->id]);
    xrLine($setup, $party, '50.00');
    $setup['rule']->update(['target_folio_id' => $party->id]);

    $settled = app(SettleToCityLedger::class)($setup['user'], $party);

    expect($settled->refusal)->toBe(ArInvoiceResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES)
        ->and($settled->rooms)->toBe(['Room 102 (10–11 Oct)'])
        ->and($party->fresh()->status)->toBe(FolioStatus::Open)
        ->and(FolioLine::query()->where('folio_id', $party->id)->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

test('the check-out preview splits the balance by room and the refusal names the other room', function () {
    $setup = xrSetup();
    $setup['rule']->delete();
    $night = $setup['stays']->nights()->orderBy('date')->first();
    $charge = xrLine($setup, $setup['outFolio'], '500.00', $night);
    xrLine($setup, $setup['outFolio'], '40.00', parent: $charge);
    xrLine($setup, $setup['outFolio'], '100.00', $setup['out']->nights()->first());
    xrLine($setup, $setup['outFolio'], '-100.00');

    $this->actingAs($setup['user'])
        ->postJson(xrUrl($setup, 'reservations.units.check-out-preview', ['unit' => $setup['out']->id]))
        ->assertOk()
        ->assertJsonPath('balance', '540.00')
        ->assertJsonPath('rooms', [
            ['name' => 'Room 101', 'amount' => '0.00', 'own' => true],
            ['name' => 'Room 102', 'amount' => '540.00', 'own' => false],
        ])
        ->assertJsonPath('otherLineIds', [$charge->id])
        ->assertJsonPath('receivesScheduled', false)
        ->assertJsonPath('canMoveCharges', true);

    $this->actingAs($setup['user'])
        ->post(xrUrl($setup, 'reservations.units.check-out', ['unit' => $setup['out']->id]))
        ->assertSessionHasErrors(['form' => 'The unit folio is not settled. It holds MYR 540.00 for Room 102. Collect it, or move those charges first.']);
});

test('a folio with only its own charges shows no split', function () {
    $setup = xrSetup();
    $setup['rule']->delete();
    xrLine($setup, $setup['outFolio'], '100.00', $setup['out']->nights()->first());

    $this->actingAs($setup['user'])
        ->postJson(xrUrl($setup, 'reservations.units.check-out-preview', ['unit' => $setup['out']->id]))
        ->assertJsonPath('rooms', [])
        ->assertJsonPath('otherLineIds', [])
        ->assertJsonPath('error', null);
});

test('the preview reports another room\'s scheduled nights', function () {
    $setup = xrSetup();

    $this->actingAs($setup['user'])
        ->postJson(xrUrl($setup, 'reservations.units.check-out-preview', ['unit' => $setup['out']->id]))
        ->assertJsonPath('receivesScheduled', true)
        ->assertJsonPath('error', 'Room 102 (10–11 Oct) still charges its scheduled nights to this folio. Send them to another folio first.');
});

test('the Move charges page ticks the lines it is given and refuses a line of another reservation', function () {
    $setup = xrSetup();
    $charge = xrLine($setup, $setup['outFolio'], '500.00', $setup['stays']->nights()->first());

    $this->actingAs($setup['user'])
        ->get(xrUrl($setup, 'reservations.folio.move', ['lines' => [$charge->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('move.targetId', $setup['staysFolio']->id)
            ->where('move.lineIds', [$charge->id]));

    $other = FolioLine::factory()->create(['folio_id' => Folio::factory()->create(['property_id' => $setup['property']->id])->id]);

    $this->actingAs($setup['user'])
        ->get(xrUrl($setup, 'reservations.folio.move', ['lines' => [$charge->id, $other->id]]))
        ->assertNotFound();
});
