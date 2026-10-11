<?php

use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\TransferLines;
use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\FolioInvoice;
use App\Services\Folio\FolioInvoiceDocument;
use App\Services\Property\PrintProfile;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner of a live property with an open business date (2026-10-10), the room revenue and city ledger codes and an
 * 8% "Service tax 8%" (code SST, number W10-1808) on room revenue.
 *
 * @return array{user: User, property: Property, reservation: Reservation, room: TransactionCode, tax: Tax}
 */
function fiSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false, 'name' => 'Service tax 8%', 'code' => 'SST', 'registration_number' => 'W10-1808']);
    $tax->applicableTransactionCodes()->attach($room);

    return ['user' => $user, 'property' => $property, 'reservation' => Reservation::factory()->create(['property_id' => $property->id]), 'room' => $room, 'tax' => $tax];
}

/** A one-night unit on 2026-10-10 priced at 100.00, in room 103 when a number is given, with its primary guest. */
function fiUnit(array $setup, ?Reservation $reservation = null, string $guest = 'Tan Wei Ming', ?string $roomNumber = '103', string $state = 'checkedIn'): ReservationUnit
{
    $factory = ReservationUnit::factory()->for($reservation ?? $setup['reservation'])->withNights();
    $unit = ($state === 'reserved' ? $factory : $factory->{$state}())->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-11']);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '100.00', 'amount' => '100.00']);

    if ($roomNumber !== null) {
        $room = Room::factory()->create(['property_id' => $setup['property']->id, 'room_type_id' => $unit->room_type_id, 'number' => $roomNumber]);
        UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['room_id' => $room->id]);
    }

    (new UnitGuest)->forceFill(['reservation_unit_id' => $unit->id, 'guest_id' => Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => $guest])->id, 'is_primary' => true])->save();

    return $unit;
}

/** Posts a plain charge line (no tax) on the folio of the unit, or on the given folio. */
function fiCharge(array $setup, ReservationUnit $unit, string $amount = '10.00', string $description = 'Laundry', ?Folio $folio = null): FolioLine
{
    $folio ??= Folio::query()->where('reservation_unit_id', $unit->id)->firstOrFail();

    return FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'kind' => TransactionKind::Charge, 'description' => $description, 'amount' => $amount, 'unit_amount' => $amount, 'posting_date' => '2026-10-10', 'source_type' => $unit->getMorphClass(), 'source_id' => $unit->id]);
}

/** Records a payment that settles what the folio holds, and returns it. */
function fiPayBalance(Folio $folio, string $receipt = 'R-1007'): Payment
{
    $due = (string) BigDecimal::of((string) $folio->lines()->sum('amount'))->toScale(2);
    $method = PaymentMethod::query()->where('property_id', $folio->property_id)->where('name', 'Card')->first() ?? PaymentMethod::factory()->create(['property_id' => $folio->property_id, 'name' => 'Card']);

    return Payment::factory()->create(['folio_id' => $folio->id, 'payment_method_id' => $method->id, 'amount' => $due, 'receipt_number' => $receipt]);
}

/** A Closed Unit folio: one posted night (100.00 and 8.00 tax), a Laundry charge, a discount, all paid by card. */
function fiClosedFolio(array $setup, ?ReservationUnit $unit = null): Folio
{
    $unit ??= fiUnit($setup);
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    app(PostRoomCharges::class)(null, $unit, CarbonImmutable::parse('2026-10-10'));
    fiCharge($setup, $unit);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'kind' => TransactionKind::Adjustment, 'description' => 'Loyalty discount', 'amount' => '-5.00', 'unit_amount' => '-5.00', 'posting_date' => '2026-10-10']);
    fiPayBalance($folio);

    return fiClose($setup, $folio);
}

function fiClose(array $setup, Folio $folio): Folio
{
    $result = app(CloseFolio::class)($setup['user'], $folio->fresh());

    expect($result->refusal)->toBeNull();

    return $folio->fresh();
}

function fiInvoice(Folio $folio): FolioInvoiceDocument
{
    return app(FolioInvoice::class)->for($folio->fresh());
}

/** The invoice view rendered to HTML, to see what the partials print. */
function fiHtml(array $setup, Folio $folio): string
{
    return view('pdf.folio-invoice', [
        'invoice' => fiInvoice($folio),
        'settings' => $setup['property']->fresh()->printSetting,
        'print' => app(PrintProfile::class)->for($setup['property']->fresh()),
    ])->render();
}

function fiUrl(array $setup, Folio $folio, ?Reservation $reservation = null): string
{
    return route('reservations.folios.invoice', ['property' => $setup['property']->code, 'reservation' => ($reservation ?? $setup['reservation'])->id, 'folio' => $folio->id]);
}

test('the total is the sum of the printed charge, discount and tax rows and the balance is 0.00', function () {
    $setup = fiSetup();
    $folio = fiClosedFolio($setup);

    $invoice = fiInvoice($folio);
    $rows = collect($invoice->lines->groups)->flatMap(fn ($group) => $group->rows)->pluck('amount');
    $taxes = collect($invoice->lines->taxes)->pluck('amount');
    $sum = $rows->concat($taxes)->reduce(fn (BigDecimal $carry, string $amount) => $carry->plus($amount), BigDecimal::zero());

    expect($invoice->lines->total)->toBe('113.00')
        ->and((string) $sum->toScale(2))->toBe($invoice->lines->total)
        ->and($rows->all())->toContain('100.00', '10.00', '-5.00')
        ->and($invoice->paid)->toBe('113.00')
        ->and($invoice->balance)->toBe('0.00')
        ->and($invoice->invoiceNumber)->toBe($folio->invoice_number)
        ->and($invoice->payments)->toHaveCount(1)
        ->and($invoice->payments[0]->receiptNumber)->toBe('R-1007')
        ->and($invoice->payments[0]->text)->toBe('Card');
});

test('a charge transferred out of the folio does not print and its copy prints on the other folio', function () {
    $setup = fiSetup();
    $unit = fiUnit($setup, state: 'checkedOut');
    $unitFolio = app(CreateFolio::class)($setup['reservation'], $unit);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    fiCharge($setup, $unit, '108.00', 'Room');
    $minibar = fiCharge($setup, $unit, '30.00', 'Minibar');
    expect(app(TransferLines::class)($setup['user'], [$minibar], $master)->refusal)->toBeNull();
    fiPayBalance($unitFolio);
    fiPayBalance($master, 'R-2001');
    $unitFolio = fiClose($setup, $unitFolio);
    $master = fiClose($setup, $master);

    $unitTexts = collect(fiInvoice($unitFolio)->lines->groups)->flatMap(fn ($group) => collect($group->rows)->map->text())->all();
    $masterTexts = collect(fiInvoice($master)->lines->groups)->flatMap(fn ($group) => collect($group->rows)->map->text())->all();

    expect($unitTexts)->not->toContain('Minibar')->toContain('Room')
        ->and($masterTexts)->toBe(['Minibar'])
        ->and(fiInvoice($unitFolio)->lines->total)->toBe('108.00')
        ->and(fiInvoice($master)->lines->total)->toBe('30.00');
});

test('a folio settled to the city ledger prints the company and the AR invoice number', function () {
    $setup = fiSetup();
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Acme Sdn Bhd', 'payment_terms_days' => 30]);
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'amount' => '108.00', 'unit_amount' => '108.00', 'posting_date' => '2026-10-10']);
    expect(app(SettleToCityLedger::class)($setup['user'], $folio->fresh())->refusal)->toBeNull();
    $folio = $folio->fresh();
    $ar = ArInvoice::query()->where('folio_id', $folio->id)->sole();

    $invoice = fiInvoice($folio);

    expect($folio->status)->toBe(FolioStatus::Closed)
        ->and($invoice->payments)->toHaveCount(1)
        ->and($invoice->payments[0]->text)->toBe('Billed to Acme Sdn Bhd, invoice '.$ar->number)
        ->and($invoice->paid)->toBe('0.00')
        ->and($invoice->billed)->toBe('108.00')
        ->and($invoice->balance)->toBe('0.00');
});

test('the tax registration number prints next to each tax and under the single Tax line with the breakdown off', function () {
    $setup = fiSetup();
    $levy = Tax::factory()->for($setup['property'])->create(['rate' => '2.00', 'name' => 'Tourism tax', 'code' => 'TTX', 'registration_number' => null]);
    $levy->applicableTransactionCodes()->attach($setup['room']);
    $folio = fiClosedFolio($setup);

    $invoice = fiInvoice($folio);
    $byLabel = collect($invoice->lines->taxes)->keyBy('label');

    expect($byLabel['Service tax 8%']->numbers)->toBe(['SST W10-1808'])
        ->and($byLabel['Tourism tax']->numbers)->toBe([])
        ->and(fiHtml($setup, $folio))->toContain('Service tax 8% · SST W10-1808')->not->toContain('Tourism tax ·');

    $setup['property']->printSetting->forceFill(['tax_breakdown' => false])->save();
    $single = fiInvoice($folio)->lines->taxes;
    $html = fiHtml($setup, $folio);

    expect($single)->toHaveCount(1)
        ->and($single[0]->label)->toBe('Tax')
        ->and($single[0]->numbers)->toBe(['SST W10-1808'])
        ->and($html)->toContain('SST W10-1808')->not->toContain('Service tax 8% ·');
});

test('Bill to is the payer company, else the payer guest, else the booker, with only the parts that are set', function () {
    $setup = fiSetup();
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $booker = $setup['reservation']->booker;
    $booker->forceFill(['full_name' => 'Aminah Rahman', 'address_line1' => null, 'address_line2' => null, 'city' => null, 'postal_code' => null, 'state' => null, 'country_code' => 'MY'])->save();
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'amount' => '50.00', 'unit_amount' => '50.00', 'posting_date' => '2026-10-10']);
    fiPayBalance($folio);
    fiClose($setup, $folio);

    $billTo = fn () => fiInvoice($folio)->strip[0];

    expect($billTo())->toBe(['label' => 'Bill to', 'lines' => ['Aminah Rahman']]);

    $payer = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Tan Wei Ming', 'address_line1' => '12 Jalan Aman', 'address_line2' => null, 'postal_code' => '50450', 'city' => 'Kuala Lumpur', 'state' => null, 'country_code' => 'MY']);
    DB::table('folios')->where('id', $folio->id)->update(['payer_guest_id' => $payer->id]);

    expect($billTo()['lines'])->toBe(['Tan Wei Ming', '12 Jalan Aman', '50450 Kuala Lumpur', 'Malaysia']);

    $company = Company::factory()->for($setup['property'])->create(['name' => 'Acme Sdn Bhd', 'tin' => 'C2584563200', 'sst_registration_number' => 'W10-9', 'billing_address_line1' => '8 Jalan Ampang', 'billing_address_line2' => null, 'billing_city' => 'Kuala Lumpur', 'billing_postal_code' => null, 'billing_state_code' => null, 'billing_country_code' => null, 'brn' => null]);
    DB::table('folios')->where('id', $folio->id)->update(['payer_guest_id' => null, 'payer_company_id' => $company->id]);
    $lines = $billTo()['lines'];

    expect($lines[0])->toBe('Acme Sdn Bhd')
        ->and($lines)->toContain('8 Jalan Ampang', 'Kuala Lumpur', 'TIN C2584563200', 'SST no. W10-9')
        ->and($lines)->not->toContain('Tan Wei Ming');
});

test('the info strip leaves out empty cells', function () {
    $setup = fiSetup();
    $folio = fiClosedFolio($setup);

    $labels = fn () => collect(fiInvoice($folio)->strip)->pluck('label')->all();

    expect($labels())->toBe(['Bill to', 'Stay', 'Reservation'])
        ->and(fiInvoice($folio)->strip[1]['lines'])->toBe(['10–11 Oct 2026 · 1 night']);

    $setup['reservation']->forceFill(['external_reference' => 'PO-77'])->save();

    expect($labels())->toBe(['Bill to', 'Stay', 'Reservation', 'Your ref']);
});

test('the Rooms block prints one line per unit only with Room and guest on lines off', function () {
    $setup = fiSetup();
    $folio = fiClosedFolio($setup);

    expect(fiInvoice($folio)->rooms)->toBe([])
        ->and(fiHtml($setup, $folio))->not->toContain('Rooms');

    $setup['property']->printSetting->forceFill(['show_unit_on_lines' => false])->save();

    expect(fiInvoice($folio)->rooms)->toBe(['Room 103 · Tan Wei Ming · 10–11 Oct'])
        ->and(fiHtml($setup, $folio))->toContain('Rooms')->toContain('Room 103 · Tan Wei Ming · 10–11 Oct');
});

test('the Rooms block names every unit that has a charge on a master folio', function () {
    $setup = fiSetup();
    $setup['property']->printSetting->forceFill(['show_unit_on_lines' => false])->save();
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $one = fiUnit($setup, roomNumber: '103', state: 'reserved');
    $two = fiUnit($setup, guest: 'Lee Mei Ling', roomNumber: '104', state: 'reserved');
    $idle = fiUnit($setup, guest: 'No Charge', roomNumber: '105', state: 'reserved');
    foreach ([$one, $two] as $unit) {
        FolioLine::factory()->create(['folio_id' => $master->id, 'transaction_code_id' => $setup['room']->id, 'amount' => '50.00', 'unit_amount' => '50.00', 'posting_date' => '2026-10-10', 'source_type' => $unit->getMorphClass(), 'source_id' => $unit->id]);
    }
    fiPayBalance($master);
    $master = fiClose($setup, $master);

    expect(fiInvoice($master)->rooms)->toBe(['Room 103 · Tan Wei Ming · 10–11 Oct', 'Room 104 · Lee Mei Ling · 10–11 Oct'])
        ->and($idle->exists)->toBeTrue();
});

test('the footer prints the amount in words and the signature lines only when they are switched on', function () {
    $setup = fiSetup();
    $folio = fiClosedFolio($setup);

    expect(fiInvoice($folio)->totalInWords)->toBeNull()
        ->and(fiHtml($setup, $folio))->not->toContain('Cashier');

    $setup['property']->printSetting->forceFill(['show_amount_in_words' => true, 'show_signature_lines' => true])->save();
    $html = fiHtml($setup, $folio);

    expect(fiInvoice($folio)->totalInWords)->toContain('one hundred and thirteen')
        ->and($html)->toContain('Cashier')->toContain('Guest ____');
});

test('the invoice streams inline as invoice-number.pdf', function () {
    $setup = fiSetup();
    $folio = fiClosedFolio($setup);

    $response = $this->actingAs($setup['user'])->get(fiUrl($setup, $folio))->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('inline')->toContain('invoice-'.$folio->invoice_number.'.pdf');
});

test('an Open folio, a Voided folio and a folio of another reservation are a 404', function () {
    $setup = fiSetup();
    $open = app(CreateFolio::class)($setup['reservation'], fiUnit($setup));
    $voided = Folio::factory()->voided()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $otherReservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $otherFolio = fiClosedFolio([...$setup, 'reservation' => $otherReservation], fiUnit($setup, $otherReservation, roomNumber: null));

    $this->actingAs($setup['user'])->get(fiUrl($setup, $open))->assertNotFound();
    $this->actingAs($setup['user'])->get(fiUrl($setup, $voided))->assertNotFound();
    $this->actingAs($setup['user'])->get(fiUrl($setup, $otherFolio))->assertNotFound();
    $this->actingAs($setup['user'])->get(fiUrl($setup, $otherFolio, $otherReservation))->assertOk();
    expect($open->status)->toBe(FolioStatus::Open);
});

test('a user without folio.print-voucher gets a 403', function () {
    $setup = fiSetup();
    $folio = fiClosedFolio($setup);
    $staff = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($staff)->get(fiUrl($setup, $folio))->assertForbidden();
});

test('the folio tab offers Print invoice on a Closed folio to a user who may print', function () {
    $setup = fiSetup();
    $folio = fiClosedFolio($setup);
    $open = app(CreateFolio::class)($setup['reservation'], fiUnit($setup, roomNumber: null));
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $folio->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn ($page) => $page->where('folio.canPrintInvoice', true));
    $this->actingAs($setup['user'])->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $open->id]))->assertInertia(fn ($page) => $page->where('folio.canPrintInvoice', false));
});
