<?php

use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationCompany;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PermissionEnum;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\CityLedger\CreditCheck;
use App\Services\CityLedger\CreditExposure;
use App\Services\Reservations\StayTotal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, an actor and a company with the given credit limit.
 *
 * @return array{property: Property, actor: User, company: Company}
 */
function creditSetup(?string $limit = '1000.00'): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);

    return [
        'property' => $property,
        'actor' => User::factory()->create(['property_id' => $property->id]),
        'company' => Company::factory()->for($property)->create(['credit_limit' => $limit]),
    ];
}

function creditFolio(array $setup, string $amount, array $folio = [], array $reservation = []): Folio
{
    $folio = Folio::factory()->master()->create([
        'property_id' => $setup['property']->id,
        'payer_company_id' => $setup['company']->id,
        'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id, ...$reservation])->id,
        ...$folio,
    ]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => $amount, 'unit_amount' => $amount, 'posting_date' => today()->toDateString()]);

    return $folio;
}

test('open AR is invoices and credit notes minus payments, never below 0', function () {
    $setup = creditSetup();
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '400.00']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '100.00']);
    ArInvoice::factory()->void()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '999.00']);
    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '150.00']);
    ArPayment::factory()->voided()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '777.00']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id]);
    $exposure = app(CreditExposure::class);

    expect($exposure->openAr([$setup['company']->id])[$setup['company']->id])->toBe('350.00');

    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '5000.00']);

    expect($exposure->openAr([$setup['company']->id])[$setup['company']->id])->toBe('0.00');
});

test('allocations do not change open AR', function () {
    $setup = creditSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '400.00']);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '150.00']);
    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '150.00']);

    expect(app(CreditExposure::class)->openAr([$setup['company']->id])[$setup['company']->id])->toBe('250.00');
});

test('open folio balance counts every Open company folio whatever the reservation status, and ignores credit, closed and voided folios', function () {
    $setup = creditSetup();
    creditFolio($setup, '300.00');
    creditFolio($setup, '-50.00');
    creditFolio($setup, '900.00', ['status' => FolioStatus::Closed]);
    creditFolio($setup, '900.00', ['status' => FolioStatus::Voided]);
    creditFolio($setup, '900.00', [], ['status' => ReservationStatus::Cancelled]);
    creditFolio($setup, '900.00', [], ['status' => ReservationStatus::Completed]);
    creditFolio($setup, '100.00', ['type' => FolioType::ThirdParty]);
    Folio::factory()->master()->create(['property_id' => $setup['property']->id]);
    creditFolio(['property' => $setup['property'], 'company' => Company::factory()->for($setup['property'])->create()], '777.00');

    expect(app(CreditExposure::class)->inHouse([$setup['company']->id], $setup['property']->id)[$setup['company']->id])->toBe('2200.00');
});

test('a departed master left unbilled counts in the exposure, and settling it moves it into AR without changing the exposure', function () {
    $setup = creditSetup('1000.00');
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '800.00']);
    $folio = creditFolio($setup, '200.00', [], ['status' => ReservationStatus::Completed]);
    $exposure = app(CreditExposure::class);

    $before = $exposure->forCompany($setup['company']);
    $result = app(SettleToCityLedger::class)($setup['actor'], $folio);
    $after = $exposure->forCompany($setup['company']->fresh());

    expect($result->isRefused())->toBeFalse()
        ->and($before)->toMatchArray(['openAr' => '800.00', 'inHouse' => '200.00', 'exposure' => '1000.00'])
        ->and($after)->toMatchArray(['openAr' => '1000.00', 'inHouse' => '0.00', 'exposure' => '1000.00']);
});

test('exposure adds both and the headroom follows the limit', function () {
    $setup = creditSetup('1000.00');
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '400.00']);
    creditFolio($setup, '250.00');

    $figures = app(CreditExposure::class)->forCompany($setup['company']);

    expect($figures)->toBe(['openAr' => '400.00', 'inHouse' => '250.00', 'exposure' => '650.00', 'limit' => '1000.00', 'headroom' => '350.00']);

    $setup['company']->update(['credit_limit' => null]);

    expect(app(CreditExposure::class)->forCompany($setup['company']->fresh()))->toMatchArray(['limit' => null, 'headroom' => null]);
});

test('the check refuses only when exposure plus the amount goes over the limit', function (string $additional, bool $allowed, string $overBy) {
    $setup = creditSetup('1000.00');
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '900.00']);

    $result = app(CreditCheck::class)->check($setup['company'], $additional);

    expect($result->allowed)->toBe($allowed)
        ->and($result->overBy)->toBe($overBy)
        ->and($result->exposure)->toBe('900.00')
        ->and($result->limit)->toBe('1000.00');
})->with([
    'nothing added' => ['0.00', true, '0.00'],
    'up to the limit' => ['100.00', true, '0.00'],
    'one cent over' => ['100.01', false, '0.01'],
    'well over' => ['350.00', false, '250.00'],
]);

test('a company with no limit always passes', function () {
    $setup = creditSetup(null);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '999999.00']);

    expect(app(CreditCheck::class)->check($setup['company'], '5000.00')->allowed)->toBeTrue();
});

test('the stay total sums the live units only', function () {
    $setup = creditSetup();
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    ReservationUnit::factory()->for($reservation)->withNights()->create();
    $total = app(StayTotal::class)->forReservation($reservation);
    ReservationUnit::factory()->for($reservation)->withNights()->create(['status' => UnitStatus::Cancelled]);

    expect(BigDecimal::of($total)->isPositive())->toBeTrue()
        ->and(app(StayTotal::class)->forReservation($reservation))->toBe($total);
});

test('changing the company is refused when the stay total would take it over the limit', function () {
    $setup = creditSetup('50.00');
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    ReservationUnit::factory()->for($reservation)->withNights()->create();
    $action = app(UpdateReservationCompany::class);

    $refused = $action($setup['actor'], $reservation, $setup['company']->id);

    expect($refused->refusal)->toBe(ReservationResult::REFUSAL_CREDIT_LIMIT_EXCEEDED)
        ->and($reservation->fresh()->company_id)->toBeNull();

    $done = $action($setup['actor'], $reservation, $setup['company']->id, 'Owner agreed');

    expect($done->isRefused())->toBeFalse()
        ->and($reservation->fresh()->company_id)->toBe($setup['company']->id)
        ->and(Activity::query()->where('event', 'credit.overridden')->sole()->properties['action'])->toBe('change_reservation_company');
});

test('clearing the company is never credit checked', function () {
    $setup = creditSetup('50.00');
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id]);
    ReservationUnit::factory()->for($reservation)->withNights()->create();

    expect(app(UpdateReservationCompany::class)($setup['actor'], $reservation, null)->isRefused())->toBeFalse();
});

test('company.credit-override goes to Owner and Manager only', function (DefaultRoleEnum $role, bool $granted) {
    expect(in_array(PermissionEnum::CompanyCreditOverride, $role->permissions(), true))->toBe($granted);
})->with([
    [DefaultRoleEnum::Owner, true],
    [DefaultRoleEnum::Manager, true],
    [DefaultRoleEnum::Finance, false],
    [DefaultRoleEnum::FrontDesk, false],
    [DefaultRoleEnum::Housekeeping, false],
]);

test('exposure for several companies runs in three queries and matches each company alone', function () {
    $setup = creditSetup('1000.00');
    $other = Company::factory()->for($setup['property'])->create(['credit_limit' => '100.00']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '400.00']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $other->id, 'amount' => '250.00']);
    creditFolio($setup, '100.00');
    $exposure = app(CreditExposure::class);
    $companies = collect([$setup['company'], $other]);

    DB::enableQueryLog();
    $both = $exposure->forCompanies($companies);

    expect(count(DB::getQueryLog()))->toBe(3)
        ->and($both[$setup['company']->id])->toBe($exposure->forCompany($setup['company']))
        ->and($both[$other->id]['exposure'])->toBe('250.00')
        ->and($exposure->isOverLimit($both[$other->id]))->toBeTrue()
        ->and($exposure->isOverLimit($both[$setup['company']->id]))->toBeFalse();
});
