<?php

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationCompany;
use App\Enums\ArInvoiceStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
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

    expect($exposure->openAr($setup['company']))->toBe('350.00');

    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '5000.00']);

    expect($exposure->openAr($setup['company']))->toBe('0.00');
});

test('allocations do not change open AR', function () {
    $setup = creditSetup();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '400.00']);
    $payment = ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '150.00']);
    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '150.00']);

    expect(app(CreditExposure::class)->openAr($setup['company']))->toBe('250.00');
});

test('in-house balance counts the open folios of live reservations and ignores credit', function () {
    $setup = creditSetup();
    creditFolio($setup, '300.00');
    creditFolio($setup, '-50.00');
    creditFolio($setup, '900.00', ['status' => FolioStatus::Closed]);
    creditFolio($setup, '900.00', [], ['status' => ReservationStatus::Cancelled]);
    creditFolio($setup, '900.00', [], ['status' => ReservationStatus::Completed]);
    Folio::factory()->master()->create(['property_id' => $setup['property']->id]);

    expect(app(CreditExposure::class)->inHouse($setup['company']))->toBe('300.00');
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

test('settling is refused for a company already over its limit unless a reason is given', function () {
    $setup = creditSetup('500.00');
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'amount' => '600.00']);
    $folio = creditFolio($setup, '100.00');

    $refused = app(SettleToCityLedger::class)($setup['actor'], $folio);

    expect($refused->refusal)->toBe(ArInvoiceResult::REFUSAL_CREDIT_LIMIT_EXCEEDED)
        ->and($folio->fresh()->status)->toBe(FolioStatus::Open)
        ->and(Activity::query()->where('event', 'credit.overridden')->count())->toBe(0);

    app(SettleToCityLedger::class)($setup['actor'], $folio, '   ');

    expect($folio->fresh()->status)->toBe(FolioStatus::Open);

    $done = app(SettleToCityLedger::class)($setup['actor'], $folio, 'Approved by the owner');

    $activity = Activity::query()->where('event', 'credit.overridden')->sole();

    expect($done->isRefused())->toBeFalse()
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed)
        ->and($activity->subject_id)->toBe($setup['company']->id)
        ->and($activity->properties['reason'])->toBe('Approved by the owner')
        ->and($activity->properties['action'])->toBe('settle_to_city_ledger')
        ->and($activity->properties['exposure'])->toBe('700.00')
        ->and($activity->properties['over_by'])->toBe('200.00');
});

test('settling a folio that is under the limit needs no override, and an unused reason logs nothing', function () {
    $setup = creditSetup('500.00');
    $folio = creditFolio($setup, '400.00');

    $result = app(SettleToCityLedger::class)($setup['actor'], $folio, 'Not needed');

    expect($result->isRefused())->toBeFalse()
        ->and(Activity::query()->where('event', 'credit.overridden')->count())->toBe(0)
        ->and($result->invoice->status)->toBe(ArInvoiceStatus::Open);
});

test('the stay total sums the live units only', function () {
    $setup = creditSetup();
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    ReservationUnit::factory()->for($reservation)->withNights()->create();
    $total = app(StayTotal::class)->forReservation($reservation);
    ReservationUnit::factory()->for($reservation)->withNights()->create(['status' => UnitStatus::Cancelled]);

    expect(bccomp($total, '0', 2))->toBe(1)
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

test('the settle route passes the override reason only for users who may override', function (DefaultRoleEnum $role, bool $settled) {
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);
    $company = Company::factory()->for($property)->create(['credit_limit' => '10.00']);
    ArInvoice::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '600.00']);
    $folio = creditFolio(['property' => $property, 'company' => $company], '100.00');
    $url = route('reservations.folios.city-ledger', ['property' => $property->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);

    $this->actingAs($user)->post($url, ['credit_override_reason' => 'Approved'])->assertRedirect();

    expect($folio->fresh()->status)->toBe($settled ? FolioStatus::Closed : FolioStatus::Open);
})->with([
    'owner' => [DefaultRoleEnum::Owner, true],
    'finance' => [DefaultRoleEnum::Finance, false],
]);
