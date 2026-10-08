<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReverseLine;
use App\Data\Folio\NewChargeData;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\FolioVoucher;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner, a property with an open business date, a reservation with a checked-in unit and its Unit folio, and a
 * charge item whose code carries an 8% tax, so a charge posts with a tax child line.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, folio: Folio, item: ChargeItem}
 */
function fvSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
    $item = ChargeItem::factory()->create(['property_id' => $property->id, 'default_amount' => null]);
    Tax::factory()->for($property)->create(['rate' => '8.00'])->applicableTransactionCodes()->attach($item->transaction_code_id);

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'unit' => $unit, 'folio' => app(CreateFolio::class)($reservation, $unit), 'item' => $item];
}

/** Posts a 100.00 charge, so the folio gets the charge line and one tax child. */
function fvPost(array $setup, string $amount = '100.00'): FolioLine
{
    return app(PostCharge::class)($setup['user'], $setup['unit'], NewChargeData::from([
        'chargeItemId' => $setup['item']->id,
        'transactionCodeId' => null,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]))->line();
}

function fvUrl(array $setup, ?Folio $folio = null, array $lines = []): string
{
    return route('reservations.folios.voucher', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => ($folio ?? $setup['folio'])->id, 'lines' => $lines]);
}

test('a voucher for a charge and its tax line opens as an inline PDF', function () {
    $setup = fvSetup();
    $charge = fvPost($setup);
    $ids = [$charge->id, ...$charge->children()->pluck('id')->all()];

    $response = $this->actingAs($setup['user'])->get(fvUrl($setup, lines: $ids));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('inline')->toContain('voucher-'.$setup['folio']->number);
});

test('a voucher holds any printable line, a tax line on its own and a closed folio included', function () {
    $setup = fvSetup();
    $charge = fvPost($setup);
    $tax = $charge->children()->sole();
    app(CloseFolio::class)($setup['user'], $setup['folio']->refresh());

    $this->actingAs($setup['user'])->get(fvUrl($setup, lines: [$tax->id]))->assertOk();
});

test('the document lists the chosen lines in ledger order with their level and the signed total', function () {
    $setup = fvSetup();
    $charge = fvPost($setup);
    $tax = $charge->children()->sole();
    fvPost($setup, '10.00');

    $document = app(FolioVoucher::class)->forLines($setup['folio'], [$tax->id, $charge->id]);

    expect(collect($document->rows)->map(fn (array $row): array => [$row['level'], $row['amount']])->all())->toBe([[0, '100.00'], [1, '8.00']])
        ->and($document->total)->toBe('108.00')
        ->and($document->folioNumber)->toBe($setup['folio']->number)
        ->and($document->reservationReference)->toBe($setup['reservation']->reference);
});

test('a voucher names the company billed for the folio, and none when a guest pays', function () {
    $setup = fvSetup();
    $charge = fvPost($setup);
    $service = app(FolioVoucher::class);
    $guestPays = $service->forLines($setup['folio'], [$charge->id]);
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Acme Sdn Bhd']);
    $setup['folio']->forceFill(['payer_guest_id' => null, 'payer_company_id' => $company->id])->save();

    $companyPays = $service->forLines($setup['folio']->fresh(), [$charge->id]);

    expect($guestPays->billedTo)->toBeNull()
        ->and($companyPays->billedTo)->toBe('Acme Sdn Bhd')
        ->and($companyPays->guestName)->toBe($setup['reservation']->booker->full_name);
});

test('the printable lines leave out payments, reversals and reversed lines', function () {
    $setup = fvSetup();
    $kept = fvPost($setup);
    $reversed = fvPost($setup, '20.00');
    app(ReverseLine::class)($setup['user'], $reversed);
    FolioLine::factory()->create(['folio_id' => $setup['folio']->id, 'kind' => 'payment', 'amount' => '-50.00', 'unit_amount' => '50.00']);

    $ids = app(FolioVoucher::class)->printableLines($setup['folio'])->pluck('id')->all();

    expect($ids)->toBe([$kept->id, ...$kept->children()->orderBy('id')->pluck('id')->all()]);
});

test('a voucher refuses a payment, a reversal or a reversed line', function (string $which) {
    $setup = fvSetup();
    $charge = fvPost($setup);
    $reversal = app(ReverseLine::class)($setup['user'], $charge)->line();
    $payment = FolioLine::factory()->create(['folio_id' => $setup['folio']->id, 'kind' => 'payment', 'amount' => '-50.00', 'unit_amount' => '50.00']);
    $id = match ($which) {
        'reversed' => $charge->id,
        'reversal' => $reversal->id,
        'payment' => $payment->id,
    };

    $this->actingAs($setup['user'])->get(fvUrl($setup, lines: [$id]))->assertStatus(422);
})->with(['reversed', 'reversal', 'payment']);

test('a line of another folio is a 404, and so is a folio of another property', function () {
    $setup = fvSetup();
    $other = fvSetup();
    $foreign = fvPost($other);
    $ownLine = fvPost($setup);

    $this->actingAs($setup['user'])->get(fvUrl($setup, lines: [$foreign->id]))->assertNotFound();
    $this->actingAs($setup['user'])->get(fvUrl($setup, lines: [$ownLine->id, $foreign->id]))->assertNotFound();
    $this->actingAs($setup['user'])->get(fvUrl($setup, $other['folio'], [$foreign->id]))->assertNotFound();
});

test('the lines are checked: at least one, no more than the cap, whole numbers', function (array $lines) {
    $setup = fvSetup();

    $this->actingAs($setup['user'])->get(fvUrl($setup, lines: $lines))->assertSessionHasErrors('lines');
})->with([
    'none' => [[]],
    'over the cap' => [range(1, FolioVoucher::MAX_LINES + 1)],
]);

test('a user without folio.print-voucher gets a 403', function () {
    $setup = fvSetup();
    $line = fvPost($setup);
    $staff = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($staff)->get(fvUrl($setup, lines: [$line->id]))->assertForbidden();
});

test('the folio tab marks the printable rows and the user who may print', function () {
    $setup = fvSetup();
    $charge = fvPost($setup);
    $payment = FolioLine::factory()->create(['folio_id' => $setup['folio']->id, 'kind' => 'payment', 'amount' => '-50.00', 'unit_amount' => '50.00']);
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canPrintVoucher', true)
        ->where('voucher.maxLines', FolioVoucher::MAX_LINES)
        ->where('folio.rows', fn ($rows) => collect($rows)->firstWhere('lineId', $charge->id)['printable'] === true
            && collect($rows)->firstWhere('lineId', $charge->id)['kind'] === 'charge'
            && collect($rows)->firstWhere('lineId', $payment->id)['printable'] === false));

    $staff = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);
    $this->actingAs($staff)->get($url)->assertForbidden();
});

/** A unit with two Scheduled nights of 100.00 and no posted line yet, plus the room revenue code the page needs. */
function fvScheduledUnit(array $setup, bool $withFolio = false): ReservationUnit
{
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);
    $unit = ReservationUnit::factory()->for($setup['reservation'])->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '100.00', 'amount' => '100.00']);

    if ($withFolio) {
        app(CreateFolio::class)($setup['reservation'], $unit);
    }

    return $unit;
}

function fvUnitUrl(array $setup, ReservationUnit $unit, array $scheduled = [], array $lines = []): string
{
    return route('reservations.units.voucher', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $unit->id, 'scheduled' => $scheduled, 'lines' => $lines]);
}

test('the folio tab lists Scheduled rows as printable, with a kind, and a not started unit can print', function () {
    $setup = fvSetup();
    $unit = fvScheduledUnit($setup);
    $night = $unit->nights()->orderBy('date')->first();
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => 'u'.$unit->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('folio.kind', 'not_started')
        ->where('folio.canPrintVoucher', true)
        ->where('folio.paymentUnitId', $unit->id)
        ->where('folio.rows.0.key', 'night:'.$night->id)
        ->where('folio.rows.0.printable', true)
        ->where('folio.rows.0.kind', 'charge'));
});

test('a voucher for a unit with no folio holds its Scheduled rows', function () {
    $setup = fvSetup();
    $unit = fvScheduledUnit($setup);
    $night = $unit->nights()->orderBy('date')->first();

    $response = $this->actingAs($setup['user'])->get(fvUnitUrl($setup, $unit, ['night:'.$night->id]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('voucher-'.$setup['reservation']->reference);
});

test('a unit voucher refuses an unknown row, a posted line, and a unit that already has a folio', function () {
    $setup = fvSetup();
    $unit = fvScheduledUnit($setup);
    $night = $unit->nights()->orderBy('date')->first();

    $this->actingAs($setup['user'])->get(fvUnitUrl($setup, $unit, ['night:999999']))->assertNotFound();
    $this->actingAs($setup['user'])->get(fvUnitUrl($setup, $unit, ['night:'.$night->id], [fvPost($setup)->id]))->assertStatus(422);
    $this->actingAs($setup['user'])->get(fvUnitUrl($setup, $unit))->assertSessionHasErrors('lines');

    app(CreateFolio::class)($setup['reservation'], $unit);

    $this->actingAs($setup['user'])->get(fvUnitUrl($setup, $unit, ['night:'.$night->id]))->assertNotFound();
});

test('a folio voucher takes posted lines and Scheduled rows together, with one total', function () {
    $setup = fvSetup();
    $unit = fvScheduledUnit($setup, withFolio: true);
    $folio = Folio::query()->where('reservation_unit_id', $unit->id)->sole();
    $night = $unit->nights()->orderBy('date')->first();
    $posted = FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => 'charge', 'description' => 'Minibar', 'amount' => '30.00', 'unit_amount' => '30.00', 'quantity' => 1, 'posting_date' => '2026-10-10']);
    $service = app(FolioVoucher::class);
    $scheduled = $service->scheduledRows($setup['property'], $setup['reservation'], $folio)->whereIn('key', ['night:'.$night->id])->values();

    $document = $service->forLines($folio, [$posted->id], $scheduled);

    expect(collect($document->rows)->pluck('description')->all())->toBe(['Minibar', $scheduled->first()->description])
        ->and($document->total)->toBe('130.00');

    $this->actingAs($setup['user'])
        ->get(fvUrl($setup, $folio, [$posted->id]).'&scheduled[]=night:'.$night->id)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($setup['user'])->get(fvUrl($setup, $folio, [$posted->id]).'&scheduled[]=night:999999')->assertNotFound();
});

test('a user without folio.print-voucher cannot print a unit voucher', function () {
    $setup = fvSetup();
    $unit = fvScheduledUnit($setup);
    $night = $unit->nights()->orderBy('date')->first();
    $staff = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($staff)->get(fvUnitUrl($setup, $unit, ['night:'.$night->id]))->assertForbidden();
});

test('a child printed without its parent stands at the top, and with its parent it is indented', function () {
    $setup = fvSetup();
    $charge = fvPost($setup);
    $tax = $charge->children()->sole();
    $service = app(FolioVoucher::class);

    $alone = $service->forLines($setup['folio'], [$tax->id]);
    $together = $service->forLines($setup['folio'], [$charge->id, $tax->id]);

    expect(collect($alone->rows)->pluck('level')->all())->toBe([0])
        ->and(collect($together->rows)->pluck('level')->all())->toBe([0, 1]);
});
