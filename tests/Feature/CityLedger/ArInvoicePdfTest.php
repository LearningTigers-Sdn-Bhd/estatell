<?php

use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CreateFolio;
use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\ArInvoiceType;
use App\Enums\DefaultRoleEnum;
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
use App\Services\CityLedger\ArInvoiceDocument;
use App\Services\CityLedger\ArInvoicePrinter;
use App\Services\CityLedger\CompanyBillTo;
use App\Services\Property\PrintProfile;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner of a live property with an open business date (2026-10-10), a company, the room revenue and city ledger
 * codes and an 8% "Service tax 8%" (number W10-1808) on room revenue.
 *
 * @return array{user: User, property: Property, reservation: Reservation, room: TransactionCode, company: Company}
 */
function arpSetup(): array
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
    $company = Company::factory()->for($property)->create(['name' => 'Acme Sdn Bhd', 'payment_terms_days' => 30, 'billing_address_line1' => '1 Jalan Acme', 'billing_postal_code' => '50450', 'billing_city' => 'Kuala Lumpur', 'billing_country_code' => 'MY', 'sst_registration_number' => 'W10-9999']);

    return ['user' => $user, 'property' => $property, 'reservation' => Reservation::factory()->create(['property_id' => $property->id]), 'room' => $room, 'company' => $company];
}

/** A one-night checked-out unit on 2026-10-10 at 100.00, in the given room with its primary guest. */
function arpUnit(array $setup, string $guest = 'Tan Wei Ming', string $roomNumber = '103'): ReservationUnit
{
    $unit = ReservationUnit::factory()->for($setup['reservation'])->withNights()->checkedIn()->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-11']);
    $room = Room::factory()->create(['property_id' => $setup['property']->id, 'room_type_id' => $unit->room_type_id, 'number' => $roomNumber]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '100.00', 'amount' => '100.00', 'room_id' => $room->id]);
    (new UnitGuest)->forceFill(['reservation_unit_id' => $unit->id, 'guest_id' => Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => $guest])->id, 'is_primary' => true])->save();

    return $unit;
}

/**
 * A unit folio paid by the company: a posted night (100.00 and 8.00 tax), a Laundry charge of 10.00 and a charge with
 * its reversal. A guest pays 50.00 by card when `$paid` is true. It is then settled to the city ledger.
 */
function arpInvoice(array $setup, bool $paid = true, ?ReservationUnit $unit = null): ArInvoice
{
    $unit ??= arpUnit($setup);
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    $folio->forceFill(['payer_guest_id' => null, 'payer_company_id' => $setup['company']->id])->save();
    app(PostRoomCharges::class)(null, $unit, CarbonImmutable::parse('2026-10-10'));
    $laundry = FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'kind' => TransactionKind::Charge, 'description' => 'Laundry', 'amount' => '10.00', 'unit_amount' => '10.00', 'posting_date' => '2026-10-10', 'source_type' => $unit->getMorphClass(), 'source_id' => $unit->id]);
    $wrong = FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'kind' => TransactionKind::Charge, 'description' => 'Wrong charge', 'amount' => '30.00', 'unit_amount' => '30.00', 'posting_date' => '2026-10-10', 'source_type' => $unit->getMorphClass(), 'source_id' => $unit->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'kind' => TransactionKind::Charge, 'description' => 'Wrong charge', 'amount' => '-30.00', 'unit_amount' => '-30.00', 'posting_date' => '2026-10-10', 'reversal_of_id' => $wrong->id]);

    if ($paid) {
        $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Card']);
        Payment::factory()->create(['folio_id' => $folio->id, 'payment_method_id' => $method->id, 'amount' => '50.00', 'receipt_number' => 'R-1004']);
    }

    expect(app(SettleToCityLedger::class)($setup['user'], $folio->fresh())->refusal)->toBeNull()
        ->and($laundry->exists)->toBeTrue();

    return ArInvoice::query()->where('folio_id', $folio->id)->sole();
}

function arpDocument(ArInvoice $invoice): ArInvoiceDocument
{
    return app(ArInvoicePrinter::class)->forInvoice($invoice->fresh());
}

/** The PDF view rendered to HTML, to see what the partials print. */
function arpHtml(array $setup, ArInvoice $invoice): string
{
    return view('pdf.ar-invoice', [
        'document' => arpDocument($invoice),
        'settings' => $setup['property']->fresh()->printSetting,
        'print' => app(PrintProfile::class)->for($setup['property']->fresh()),
    ])->render();
}

test('the lines are grouped by room and guest, leave out a reversed pair and print the tax number', function () {
    $setup = arpSetup();
    $document = arpDocument(arpInvoice($setup));
    $headings = collect($document->lines->groups)->pluck('heading')->all();
    $texts = collect($document->lines->groups)->flatMap(fn ($group) => collect($group->rows)->map->text())->all();

    expect($headings)->toBe(['Room 103 · Tan Wei Ming'])
        ->and(implode('|', $texts))->not->toContain('Wrong charge')->toContain('Laundry')
        ->and($document->lines->taxes[0]->numbers)->toBe(['SST W10-1808'])
        ->and(arpHtml($setup, ArInvoice::query()->sole()))->toContain('Service tax 8% · SST W10-1808');
});

test('with the breakdown off the nights merge into one row', function () {
    $setup = arpSetup();
    $unit = ReservationUnit::factory()->for($setup['reservation'])->withNights(2)->checkedIn()->create(['arrival_date' => '2026-10-09', 'departure_date' => '2026-10-11']);
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    $folio->forceFill(['payer_guest_id' => null, 'payer_company_id' => $setup['company']->id])->save();

    foreach ($unit->nights()->orderBy('date')->get() as $night) {
        FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'kind' => TransactionKind::Charge, 'description' => 'Room', 'amount' => '100.00', 'unit_amount' => '100.00', 'posting_date' => $night->date->toDateString(), 'source_type' => $night->getMorphClass(), 'source_id' => $night->id, 'idempotency_key' => 'night:'.$night->id]);
    }

    expect(app(SettleToCityLedger::class)($setup['user'], $folio->fresh())->refusal)->toBeNull();
    $invoice = ArInvoice::query()->sole();
    $texts = fn () => collect(arpDocument($invoice)->lines->groups)->flatMap(fn ($group) => collect($group->rows)->map->text())->all();

    $setup['property']->printSetting->forceFill(['nightly_breakdown' => true])->save();

    expect($texts())->toBe(['Room', 'Room']);

    $setup['property']->printSetting->forceFill(['nightly_breakdown' => false])->save();

    expect($texts())->toBe(['Room, 2 nights × 100.00']);
});

test('the Rooms block names the staying guest with the switch off, also for one room', function () {
    $setup = arpSetup();
    $invoice = arpInvoice($setup);

    expect(arpDocument($invoice)->rooms)->toBe([])
        ->and(arpHtml($setup, $invoice))->not->toContain('Rooms');

    $setup['property']->printSetting->forceFill(['show_unit_on_lines' => false])->save();

    expect(arpDocument($invoice)->rooms)->toBe(['Room 103 · Tan Wei Ming · 10–11 Oct'])
        ->and(arpHtml($setup, $invoice))->toContain('Rooms')->toContain('Room 103 · Tan Wei Ming · 10–11 Oct');
});

test('the guest payment prints under Paid at the hotel and total minus paid is the amount due', function () {
    $setup = arpSetup();
    $invoice = arpInvoice($setup);
    $document = arpDocument($invoice);
    $html = arpHtml($setup, $invoice);

    expect($document->payments)->toHaveCount(1)
        ->and($document->payments[0]->receiptNumber)->toBe('R-1004')
        ->and($document->paid)->toBe('50.00')
        ->and($document->lines->total)->toBe('118.00')
        ->and($invoice->amount)->toBe('68.00')
        ->and((string) BigDecimal::of($document->lines->total)->minus($document->paid)->toScale(2))->toBe($document->amountDue)
        ->and($html)->toContain('Paid at the hotel')->toContain('R-1004')->toContain('(50.00)')->toContain('Amount due MYR')->not->toContain('Billed to');
});

test('without a payment the section is left out and the amount due is the total', function () {
    $setup = arpSetup();
    $invoice = arpInvoice($setup, paid: false);
    $document = arpDocument($invoice);

    expect($document->payments)->toBe([])
        ->and($document->lines->total)->toBe($document->amountDue)
        ->and($document->amountDue)->toBe('118.00')
        ->and(arpHtml($setup, $invoice))->not->toContain('Paid at the hotel');
});

test('the amount in words spells the amount due and Payment details print when set', function () {
    $setup = arpSetup();
    $invoice = arpInvoice($setup);

    expect(arpDocument($invoice)->amountInWords)->toBeNull()
        ->and(arpDocument($invoice)->paymentDetails)->toBeNull();

    $setup['property']->printSetting->forceFill(['show_amount_in_words' => true, 'bank_details' => 'Maybank 5141 2233 4455'])->save();
    $html = arpHtml($setup, $invoice);

    expect(arpDocument($invoice)->amountInWords)->toContain('sixty-eight')
        ->and(substr_count($html, 'sixty-eight'))->toBe(1)
        ->and($html)->toContain('Payment details')->toContain('Maybank 5141 2233 4455');
});

test('the info strip names the company, the dates and the reservation', function () {
    $setup = arpSetup();
    $invoice = arpInvoice($setup);
    $strip = collect(arpDocument($invoice)->strip)->keyBy('label');

    expect($strip->keys()->all())->toBe(['Bill to', 'Issued', 'Due', 'Reservation'])
        ->and($strip['Bill to']['lines'][0])->toBe('Acme Sdn Bhd')
        ->and($strip['Reservation']['lines'])->toBe([$setup['reservation']->reference]);
});

test('an invoice without a folio prints no lines, only the strip and the amount due', function () {
    $setup = arpSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '500.00']);
    $document = arpDocument($invoice);
    $html = arpHtml($setup, $invoice);

    expect($document->lines)->toBeNull()
        ->and(collect($document->strip)->pluck('label')->all())->toBe(['Bill to', 'Issued', 'Due'])
        ->and($html)->toContain('Amount due MYR')->toContain('500.00')->not->toContain('Total MYR');
});

test('a credit note keeps its layout and ignores the switches', function () {
    $setup = arpSetup();
    $invoice = arpInvoice($setup);
    $setup['property']->printSetting->forceFill(['show_unit_on_lines' => false, 'show_amount_in_words' => true, 'bank_details' => 'Maybank 1'])->save();
    $note = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $invoice->id, 'folio_id' => null, 'due_on' => null, 'amount' => '-20.00', 'reason' => 'Wrong rate', 'number' => 'CN-7']);
    $html = arpHtml($setup, $note);

    expect(arpDocument($note)->rooms)->toBe([])
        ->and($html)->toContain('Credit note')->toContain('Corrects invoice')->toContain('Wrong rate')->toContain('MYR -20.00')
        ->and($html)->not->toContain('Rooms')->not->toContain('Payment details')->not->toContain('Amount due')->not->toContain('twenty');
});

test('a company with only a country has no address line', function () {
    $setup = arpSetup();
    $bare = Company::factory()->for($setup['property'])->create(['billing_address_line1' => null, 'billing_address_line2' => null, 'billing_postal_code' => null, 'billing_city' => null, 'billing_state_code' => null, 'billing_country_code' => 'MY', 'brn' => null, 'tin' => null, 'sst_registration_number' => null]);

    expect(app(CompanyBillTo::class)->lines($bare))->toBe([])
        ->and(app(CompanyBillTo::class)->lines($setup['company']))->toContain('Malaysia');
});
