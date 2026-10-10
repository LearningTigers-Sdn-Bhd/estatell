<?php

use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Actions\NightAudit\PostRoomCharges;
use App\Data\Folio\NewChargeData;
use App\Enums\DefaultRoleEnum;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\FolioStatement;
use App\Services\Reservations\BookingSummary;
use App\Services\Reservations\BookingSummaryDocument;
use App\Services\Reservations\ReservationDirectory;
use App\Services\Reservations\ReservationOverview;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner of a live property with an open business date (2026-10-10), the room revenue and city ledger codes and an
 * empty reservation. The room tax is 8%.
 *
 * @return array{user: User, property: Property, reservation: Reservation, room: TransactionCode}
 */
function bsSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);
    Tax::factory()->for($property)->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false, 'name' => 'Service tax 8%'])->applicableTransactionCodes()->attach($room);

    return ['user' => $user, 'property' => $property, 'reservation' => Reservation::factory()->create(['property_id' => $property->id]), 'room' => $room];
}

/** A unit of `$nights` nights from 2026-10-10 (the business date), each priced at `$amount`. */
function bsUnit(Reservation $reservation, int $nights = 3, string $amount = '100.00', ?string $roomNumber = null, bool $checkedIn = false): ReservationUnit
{
    $factory = ReservationUnit::factory()->for($reservation)->withNights();
    $unit = ($checkedIn ? $factory->checkedIn() : $factory)->create([
        'arrival_date' => '2026-10-10',
        'departure_date' => CarbonImmutable::parse('2026-10-10')->addDays($nights)->toDateString(),
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => $amount, 'amount' => $amount]);

    if ($roomNumber !== null) {
        $room = Room::factory()->create(['property_id' => $reservation->property_id, 'room_type_id' => $unit->room_type_id, 'number' => $roomNumber]);
        UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['room_id' => $room->id]);
    }

    return $unit;
}

function bsGuest(ReservationUnit $unit, string $name): void
{
    (new UnitGuest)->forceFill(['reservation_unit_id' => $unit->id, 'guest_id' => Guest::factory()->create(['property_id' => $unit->property_id, 'full_name' => $name])->id, 'is_primary' => true])->save();
}

function bsMasterFolio(array $setup): Folio
{
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->toFolio($master)->create(['reservation_id' => $setup['reservation']->id]);

    return $master;
}

function bsPostNight(ReservationUnit $unit, string $date = '2026-10-10'): void
{
    app(PostRoomCharges::class)(null, $unit, CarbonImmutable::parse($date));
}

function bsCharge(array $setup, ReservationUnit $unit, string $amount = '12.00', string $description = 'Laundry'): FolioLine
{
    return app(PostCharge::class)($setup['user'], $unit, NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $setup['room']->id,
        'description' => $description,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]))->line();
}

function bsPay(Folio $folio, string $amount = '50.00', string $receipt = 'R-1002'): Payment
{
    return Payment::factory()->create([
        'folio_id' => $folio->id,
        'payment_method_id' => (PaymentMethod::query()->where('property_id', $folio->property_id)->where('name', 'Bank transfer')->first() ?? PaymentMethod::factory()->create(['property_id' => $folio->property_id, 'name' => 'Bank transfer']))->id,
        'amount' => $amount,
        'receipt_number' => $receipt,
    ]);
}

function bsSummary(array $setup, bool $prices = true): BookingSummaryDocument
{
    return app(BookingSummary::class)->for($setup['property']->fresh(), $setup['reservation']->fresh(), $prices);
}

/** The sum of the Projected totals of every folio and not-started unit the folio tab shows. */
function bsProjectedSum(array $setup): string
{
    $reservation = $setup['reservation']->fresh()->load(['booker', 'creator', 'company']);
    $units = app(ReservationOverview::class)->build($reservation, true)['units'];
    $statement = app(FolioStatement::class);
    $rail = $statement->build($setup['property']->fresh()->load('settings'), $reservation, $units)['folios'];
    $sum = BigDecimal::zero();

    foreach ($rail as $item) {
        $sum = $sum->plus($statement->build($setup['property']->fresh()->load('settings'), $reservation, $units, $item->key)['folio']->projectedTotal);
    }

    return (string) $sum->toScale(2);
}

/** What the folios hold in payments, as a positive amount. */
function bsPayments(array $setup): string
{
    return (string) BigDecimal::of((string) FolioLine::query()->whereIn('folio_id', Folio::query()->where('reservation_id', $setup['reservation']->id)->pluck('id'))->where('kind', TransactionKind::Payment)->sum('amount'))->negated()->toScale(2);
}

function bsAssertConsistent(array $setup): BookingSummaryDocument
{
    $summary = bsSummary($setup);
    $payments = bsPayments($setup);

    expect($summary->lines->total)->toBe((string) BigDecimal::of(bsProjectedSum($setup))->plus($payments)->toScale(2))
        ->and($summary->balanceDue)->toBe((string) BigDecimal::of($summary->lines->total)->minus($summary->paid)->minus($summary->billed)->toScale(2))
        ->and((string) BigDecimal::of($summary->paid)->plus($summary->billed)->toScale(2))->toBe($payments);

    return $summary;
}

test('the total is the sum of the projected totals plus the payments for a single room', function () {
    $setup = bsSetup();
    $unit = bsUnit($setup['reservation'], 3);
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    bsPostNight($unit);
    bsPay($folio, '50.00');

    $summary = bsAssertConsistent($setup);

    expect($summary->lines->total)->toBe('324.00')
        ->and($summary->paid)->toBe('50.00')
        ->and($summary->balanceDue)->toBe('274.00');
});

test('the total holds for a master pays group', function () {
    $setup = bsSetup();
    $master = bsMasterFolio($setup);
    $one = bsUnit($setup['reservation'], 2);
    $two = bsUnit($setup['reservation'], 3, '80.00', null, true);
    bsPostNight($one);
    bsPostNight($two);
    bsCharge($setup, $two);
    bsPay($master, '100.00');

    $summary = bsAssertConsistent($setup);

    expect($summary->lines->groups)->toHaveCount(2);
});

test('the total holds for a group with a third party folio', function () {
    $setup = bsSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $master = bsMasterFolio($setup);
    $one = bsUnit($setup['reservation'], 2);
    $two = bsUnit($setup['reservation'], 2);
    $party = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $company->id]);
    FolioRoutingRule::factory()->toFolio($party)->create(['reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => $two->id]);
    bsPostNight($one);
    bsPostNight($two);
    bsPay($master, '40.00');
    bsPay($party, '60.00', 'R-2001');

    $summary = bsAssertConsistent($setup);

    expect($summary->paid)->toBe('100.00');
});

test('the total holds for a reservation with a transferred charge, which shows once', function () {
    $setup = bsSetup();
    $unit = bsUnit($setup['reservation'], 2, '100.00', null, true);
    $unitFolio = app(CreateFolio::class)($setup['reservation'], $unit);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $charge = bsCharge($setup, $unit, '30.00', 'Minibar');
    app(TransferLines::class)($setup['user'], [$charge], $master);

    $summary = bsAssertConsistent($setup);
    $texts = collect($summary->lines->groups)->flatMap(fn ($group) => collect($group->rows)->map->text())->all();

    expect(array_count_values($texts)['Minibar'])->toBe(1)
        ->and($unitFolio->lines()->count())->toBeGreaterThan(1);
});

test('paid, billed to company and the balance match the reservation list', function () {
    $setup = bsSetup();
    $company = Company::factory()->for($setup['property'])->create(['payment_terms_days' => 30, 'name' => 'Acme Sdn Bhd']);
    $setup['reservation']->forceFill(['company_id' => $company->id])->save();
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '100.00', 'unit_amount' => '100.00', 'posting_date' => '2026-10-10']);
    bsPay($folio, '20.00');
    $refunded = bsPay($folio, '40.00', 'R-1003');
    $refund = FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => TransactionKind::Payment, 'description' => 'Refund', 'amount' => '10.00', 'unit_amount' => '10.00', 'posting_date' => '2026-10-10']);
    Payment::factory()->create(['folio_id' => $folio->id, 'payment_method_id' => $refunded->payment_method_id, 'amount' => '10.00', 'refund_of_id' => $refunded->id, 'folio_line_id' => $refund->id, 'receipt_number' => null]);
    expect(app(SettleToCityLedger::class)($setup['user'], $folio->fresh())->refusal)->toBeNull();
    $other = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioLine::factory()->create(['folio_id' => $other->id, 'amount' => '30.00', 'unit_amount' => '30.00', 'posting_date' => '2026-10-10']);

    $summary = bsSummary($setup);
    $row = app(ReservationDirectory::class)->paginate($setup['property'], Request::create('/', 'GET', ['filter' => ['stay' => 'all']]))->getCollection()->firstWhere('id', $setup['reservation']->id);

    expect([(float) $row->paid, (float) $row->billed, (float) $row->balance])->toBe([50.0, 50.0, 30.0])
        ->and([$summary->paid, $summary->billed, $summary->balanceDue])->toBe(['50.00', '50.00', '30.00']);
});

test('payments list the receipt and method, a refund on its own row and the city ledger line with its invoice', function () {
    $setup = bsSetup();
    $company = Company::factory()->for($setup['property'])->create(['payment_terms_days' => 30, 'name' => 'Acme Sdn Bhd']);
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '100.00', 'unit_amount' => '100.00', 'posting_date' => '2026-10-10']);
    $payment = bsPay($folio, '40.00');
    $refund = FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => TransactionKind::Payment, 'description' => 'Refund', 'amount' => '10.00', 'unit_amount' => '10.00', 'posting_date' => '2026-10-10']);
    Payment::factory()->create(['folio_id' => $folio->id, 'payment_method_id' => $payment->payment_method_id, 'amount' => '10.00', 'refund_of_id' => $payment->id, 'folio_line_id' => $refund->id, 'receipt_number' => null]);
    expect(app(SettleToCityLedger::class)($setup['user'], $folio->fresh())->refusal)->toBeNull();
    $invoice = ArInvoice::query()->where('folio_id', $folio->id)->sole();

    $summary = bsSummary($setup);

    expect(collect($summary->payments)->map(fn ($row) => [$row->receiptNumber, $row->text, $row->amount])->all())->toEqualCanonicalizing([
        ['R-1002', 'Bank transfer', '-40.00'],
        [null, 'Refund · Bank transfer', '10.00'],
        [null, 'Billed to Acme Sdn Bhd, invoice '.$invoice->number, '-70.00'],
    ])
        ->and($summary->strip)->toHaveCount(2);
});

test('a reservation with no payments has no payments section', function () {
    $setup = bsSetup();
    bsUnit($setup['reservation'], 1);

    $summary = bsSummary($setup);

    expect($summary->payments)->toBe([])
        ->and($summary->paid)->toBe('0.00')
        ->and($summary->balanceDue)->toBe('108.00');
});

test('a reversed line and its reversal are left out and a cancelled unit shows its fee', function () {
    $setup = bsSetup();
    $kept = bsUnit($setup['reservation'], 1, '100.00', null, true);
    $cancelled = ReservationUnit::factory()->for($setup['reservation'])->cancelled()->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    bsGuest($cancelled, 'Lee Mei Ling');
    $mistake = bsCharge($setup, $kept, '25.00', 'Wrong charge');
    app(ReverseLine::class)($setup['user'], $mistake);
    $folio = Folio::factory()->forUnit($cancelled)->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $fee = FolioLine::factory()->create(['folio_id' => $folio->id, 'description' => 'Cancellation fee', 'amount' => '70.00', 'unit_amount' => '70.00', 'source_type' => $cancelled->getMorphClass(), 'source_id' => $cancelled->id, 'posting_date' => '2026-10-10']);

    $summary = bsSummary($setup);
    $headings = collect($summary->lines->groups)->map->heading->all();
    $texts = collect($summary->lines->groups)->flatMap(fn ($group) => collect($group->rows)->map->text())->all();

    expect($texts)->not->toContain('Wrong charge')
        ->and($texts)->toContain('Cancellation fee')
        ->and($headings)->toContain($cancelled->roomType->name.' (Cancelled) · Lee Mei Ling')
        ->and($summary->rooms)->toHaveCount(2)
        ->and($fee->exists)->toBeTrue();
});

test('a posted night and scheduled nights at the same price merge into one row', function () {
    $setup = bsSetup();
    $unit = bsUnit($setup['reservation'], 3);
    app(CreateFolio::class)($setup['reservation'], $unit);
    bsPostNight($unit);

    $rows = collect(bsSummary($setup)->lines->groups[0]->rows);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->text())->toStartWith('Room')->toEndWith('3 nights × 100.00')
        ->and($rows[0]->amount)->toBe('300.00');
});

test('the room column shows for two units and not for one when the grouping is off', function () {
    $setup = bsSetup();
    $setup['property']->printSetting->forceFill(['show_unit_on_lines' => false])->save();
    $one = bsUnit($setup['reservation'], 2, '100.00', '103');
    $summary = bsSummary($setup);

    expect($summary->lines->showRoomColumn)->toBeFalse();

    bsUnit($setup['reservation'], 2, '90.00', '104');
    $summary = bsSummary($setup);

    expect($summary->lines->showRoomColumn)->toBeTrue()
        ->and(collect($summary->lines->groups[0]->rows)->pluck('room')->unique()->sort()->values()->all())->toBe(['103', '104'])
        ->and($one->exists)->toBeTrue();
});

test('rooms and group headings name the room and the primary guest, and an unassigned unit shows its room type', function () {
    $setup = bsSetup();
    $assigned = bsUnit($setup['reservation'], 2, '100.00', '103');
    bsGuest($assigned, 'Tan Wei Ming');
    $assigned->forceFill(['adults' => 2])->save();
    $open = bsUnit($setup['reservation'], 2);

    $summary = bsSummary($setup);

    expect($summary->rooms[0])->toContain('Room 103')->toContain($assigned->roomType->name)->toContain($assigned->ratePlan->name)->toContain('Tan Wei Ming, 2 adults')
        ->and($summary->rooms[1])->not->toContain('Room ')
        ->and(collect($summary->lines->groups)->map->heading->all())->toBe(['Room 103 · Tan Wei Ming', $open->roomType->name]);
});

test('the info strip leaves out an empty cell and shows the company, the stay and the reference', function () {
    $setup = bsSetup();
    bsUnit($setup['reservation'], 3);

    $plain = bsSummary($setup);
    $labels = collect($plain->strip)->pluck('label')->all();

    expect($labels)->toBe(['Bill to', 'Stay', 'Booked on'])
        ->and($plain->strip[0]['value'])->toBe($setup['reservation']->booker->full_name)
        ->and($plain->strip[1]['value'])->toBe('10–13 Oct 2026 · 3 nights');

    $company = Company::factory()->for($setup['property'])->create(['name' => 'Acme Sdn Bhd']);
    $setup['reservation']->forceFill(['company_id' => $company->id, 'external_reference' => 'PO-7781'])->save();
    $full = bsSummary($setup);

    expect(collect($full->strip)->pluck('value', 'label')->all())->toMatchArray(['Bill to' => 'Acme Sdn Bhd', 'Your ref' => 'PO-7781']);
});

test('the cancellation policy follows the language and falls back to English', function () {
    $setup = bsSetup();
    bsUnit($setup['reservation'], 1);
    RoomRevenuePolicy::factory()->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::FirstNight)->create(['property_id' => $setup['property']->id, 'text_en' => 'Free until 2 days before.', 'text_ms' => 'Percuma sehingga 2 hari.']);
    RoomRevenuePolicy::factory()->forCode(SystemTransactionCode::NoShowRevenue, PolicyScope::FirstNight)->create(['property_id' => $setup['property']->id, 'text_en' => 'No-show pays one night.', 'text_ms' => '']);

    expect(bsSummary($setup)->cancellationPolicy)->toBe(['Free until 2 days before.', 'No-show pays one night.']);

    $setup['property']->settings->forceFill(['default_locale' => Locale::Malay])->save();

    expect(bsSummary($setup)->cancellationPolicy)->toBe(['Percuma sehingga 2 hari.', 'No-show pays one night.']);

    $setup['property']->printSetting->forceFill(['show_cancellation_policy' => false])->save();

    expect(bsSummary($setup)->cancellationPolicy)->toBe([]);
});

test('payment details and the amount in words print under the balance when switched on', function () {
    $setup = bsSetup();
    bsUnit($setup['reservation'], 1);

    expect(bsSummary($setup)->paymentDetails)->toBeNull()
        ->and(bsSummary($setup)->balanceInWords)->toBeNull();

    $setup['property']->printSetting->forceFill(['bank_details' => 'Maybank 5141 2233 4455', 'show_amount_in_words' => true])->save();
    $summary = bsSummary($setup);

    expect($summary->paymentDetails)->toBe('Maybank 5141 2233 4455')
        ->and($summary->balanceInWords)->toBe('Ringgit Malaysia one hundred and eight only');
});

test('a balance below zero is a credit', function () {
    $setup = bsSetup();
    $unit = bsUnit($setup['reservation'], 1);
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    bsPay($folio, '150.00');

    expect(bsSummary($setup)->balanceDue)->toBe('-42.00');
});

test('without prices no amount is held, and the request is ignored without a company', function () {
    $setup = bsSetup();
    bsUnit($setup['reservation'], 2);
    $setup['property']->printSetting->forceFill(['bank_details' => 'Maybank 5141'])->save();

    expect(bsSummary($setup, false)->showPrices)->toBeTrue();

    $company = Company::factory()->for($setup['property'])->create();
    $setup['reservation']->forceFill(['company_id' => $company->id])->save();
    $plain = bsSummary($setup, false);

    expect($plain->showPrices)->toBeFalse()
        ->and($plain->lines)->toBeNull()
        ->and($plain->payments)->toBe([])
        ->and([$plain->paid, $plain->billed, $plain->balanceDue, $plain->balanceInWords, $plain->paymentDetails])->each->toBeNull()
        ->and($plain->rooms)->toHaveCount(1);
});

test('the summary of a cancelled reservation is stamped', function () {
    $setup = bsSetup();
    $setup['reservation']->forceFill(['status' => 'cancelled'])->save();

    expect(bsSummary($setup)->stamp)->toBe('CANCELLED');
});

test('the PDF streams inline under the reservation reference', function () {
    $setup = bsSetup();
    bsUnit($setup['reservation'], 1);
    $url = route('reservations.booking-summary', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $response = $this->actingAs($setup['user'])->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('inline')->toContain('booking-summary-'.$setup['reservation']->reference.'.pdf');
});

test('the PDF prints with every section filled', function () {
    $setup = bsSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['reservation']->forceFill(['company_id' => $company->id, 'external_reference' => 'PO-1', 'status' => 'cancelled'])->save();
    $setup['property']->printSetting->forceFill(['bank_details' => 'Maybank', 'show_amount_in_words' => true])->save();
    $unit = bsUnit($setup['reservation'], 2, '100.00', '103');
    bsGuest($unit, 'Tan Wei Ming');
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    bsPay($folio, '50.00');
    RoomRevenuePolicy::factory()->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::FirstNight)->create(['property_id' => $setup['property']->id]);
    $url = route('reservations.booking-summary', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($url)->assertOk();
    $this->actingAs($setup['user'])->get($url.'?prices=0')->assertOk();
});

test('a user without reservations.view gets a 403 and another property gets a 404', function () {
    $setup = bsSetup();
    $url = route('reservations.booking-summary', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);
    $stranger = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $other = Reservation::factory()->create(['property_id' => $stranger->property_id]);
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($housekeeper)->get($url)->assertForbidden();
    $this->actingAs($setup['user'])->get(route('reservations.booking-summary', ['property' => $setup['property']->code, 'reservation' => $other->id]))->assertNotFound();

    expect(PermissionEnum::ReservationsView->value)->toBe('reservations.view');
});

test('a reservation of no-shows is stamped, lists every unit and prints no total or balance without charges', function () {
    $setup = bsSetup();
    ReservationUnit::factory()->for($setup['reservation'])->noShow()->count(2)->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);

    $summary = bsSummary($setup);

    expect($summary->stamp)->toBe('NO-SHOW')
        ->and($summary->rooms)->toHaveCount(2)
        ->and($summary->rooms)->each->toEndWith('No-show')
        ->and($summary->hasCharges())->toBeFalse()
        ->and($summary->balanceDue)->toBeNull();

    $url = route('reservations.booking-summary', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);
    $this->actingAs($setup['user'])->get($url)->assertOk();
});

test('no-shows with a payment held print the balance as a credit', function () {
    $setup = bsSetup();
    $unit = ReservationUnit::factory()->for($setup['reservation'])->noShow()->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    bsPay(app(CreateFolio::class)($setup['reservation'], $unit), '50.00');

    $summary = bsSummary($setup);

    expect($summary->hasCharges())->toBeFalse()
        ->and($summary->balanceDue)->toBe('-50.00')
        ->and($summary->payments)->toHaveCount(1);
});

test('a mixed reservation has no stamp and the no-show unit shows its status', function () {
    $setup = bsSetup();
    bsUnit($setup['reservation'], 1);
    ReservationUnit::factory()->for($setup['reservation'])->noShow()->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);

    $summary = bsSummary($setup);

    expect($summary->stamp)->toBeNull()
        ->and($summary->rooms)->toHaveCount(2)
        ->and($summary->rooms[0])->not->toContain('No-show')
        ->and($summary->rooms[1])->toEndWith('No-show');
});
