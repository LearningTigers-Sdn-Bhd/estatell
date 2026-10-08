<?php

use App\Actions\CityLedger\RecordArPayment;
use App\Data\CityLedger\NewArPaymentData;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CityLedger\FolioPaymentReferences;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, a Finance user and a company.
 *
 * @return array{user: User, property: Property, company: Company}
 */
function refSetup(DefaultRoleEnum $role = DefaultRoleEnum::Finance): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    return ['user' => $user, 'property' => $property, 'company' => Company::factory()->for($property)->create()];
}

/**
 * A Completed folio payment with the reference, on the given folio, posted on the date.
 *
 * @param  array<string, mixed>  $attributes
 */
function refFolioPayment(Folio $folio, ?string $reference, string $date = '2026-10-08', array $attributes = []): Payment
{
    $line = FolioLine::factory()->payment('100.00')->create(['folio_id' => $folio->id, 'posting_date' => $date]);

    return Payment::factory()->create([
        'folio_id' => $folio->id,
        'reference' => $reference,
        'folio_line_id' => $line->id,
        ...$attributes,
    ]);
}

function refCompanyFolio(array $setup, array $folio = [], array $reservation = []): Folio
{
    return Folio::factory()->master()->create([
        'property_id' => $setup['property']->id,
        'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id, ...$reservation])->id,
        ...$folio,
    ]);
}

function refWarning(array $setup, ?string $reference): ?string
{
    return app(FolioPaymentReferences::class)->warningFor($setup['company'], $reference);
}

test('a reference recorded on a folio the company pays matches, ignoring case and spaces', function () {
    $setup = refSetup();
    refFolioPayment(refCompanyFolio($setup, ['payer_company_id' => $setup['company']->id, 'number' => 'F-0031']), 'MBB-88231', attributes: ['receipt_number' => 'R-1002']);

    expect(refWarning($setup, '  mbb-88231 '))->toBe('Reference mbb-88231 was already recorded on folio F-0031 (receipt R-1002) on 08 Oct. Check it is not the same money before you record it.');
});

test('a payment on any folio of a reservation of the company matches too', function () {
    $setup = refSetup();
    refFolioPayment(refCompanyFolio($setup, ['number' => 'F-0031'], ['company_id' => $setup['company']->id]), 'MBB-88231', attributes: ['receipt_number' => 'R-1002']);

    expect(refWarning($setup, 'MBB-88231'))->toContain('on folio F-0031 (receipt R-1002)');
});

test('refunds, other companies, other properties, voided AR payments and blank references never match', function () {
    $setup = refSetup();
    $mine = refCompanyFolio($setup, ['payer_company_id' => $setup['company']->id]);
    $payment = refFolioPayment($mine, 'MBB-88231', attributes: ['reference' => 'OTHER']);
    refFolioPayment($mine, 'MBB-88231', attributes: ['refund_of_id' => $payment->id, 'receipt_number' => null]);
    $other = Company::factory()->for($setup['property'])->create();
    refFolioPayment(refCompanyFolio($setup, ['payer_company_id' => $other->id]), 'MBB-88231');
    refFolioPayment(Folio::factory()->master()->create(['payer_company_id' => Company::factory()->create()->id]), 'MBB-88231');
    ArPayment::factory()->voided()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'reference' => 'MBB-88231']);
    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $other->id, 'reference' => 'MBB-88231']);

    expect(refWarning($setup, 'MBB-88231'))->toBeNull()
        ->and(refWarning($setup, '   '))->toBeNull()
        ->and(refWarning($setup, null))->toBeNull();
});

test('the company\'s other AR payments match and name the receipt', function () {
    $setup = refSetup();
    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'reference' => 'MBB-88231', 'receipt_number' => 'RC-0045', 'received_on' => '2026-10-08']);

    expect(refWarning($setup, 'MBB-88231'))->toBe('Reference MBB-88231 was already recorded as AR payment RC-0045 on 08 Oct. Check it is not the same money before you record it.');
});

test('with several matches the oldest is named and the rest are counted', function () {
    $setup = refSetup();
    ArPayment::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $setup['company']->id, 'reference' => 'MBB-88231', 'receipt_number' => 'RC-0045', 'received_on' => '2026-10-09']);
    refFolioPayment(refCompanyFolio($setup, ['payer_company_id' => $setup['company']->id, 'number' => 'F-0031']), 'MBB-88231', '2026-10-07', ['receipt_number' => 'R-1002']);
    refFolioPayment(refCompanyFolio($setup, ['payer_company_id' => $setup['company']->id, 'number' => 'F-0032']), 'MBB-88231', '2026-10-08', ['receipt_number' => 'R-1003']);

    expect(refWarning($setup, 'MBB-88231'))->toBe('Reference MBB-88231 was already recorded on folio F-0031 (receipt R-1002) on 07 Oct, and 2 more. Check it is not the same money before you record it.');
});

test('the route answers with the warning, writes and logs nothing, and needs city-ledger.payment on a company of the property', function () {
    $setup = refSetup();
    refFolioPayment(refCompanyFolio($setup, ['payer_company_id' => $setup['company']->id, 'number' => 'F-0031']), 'MBB-88231', attributes: ['receipt_number' => 'R-1002']);
    $url = route('finance.city-ledger.companies.payments.reference-check', ['property' => $setup['property']->code, 'company' => $setup['company']->id]);
    $before = Activity::query()->count();

    $this->actingAs($setup['user'])->postJson($url, ['reference' => 'MBB-88231'])
        ->assertOk()
        ->assertJsonPath('warning', fn ($warning) => str_contains((string) $warning, 'folio F-0031'));
    $this->postJson($url, ['reference' => 'NEW-1'])->assertOk()->assertJsonPath('warning', null);

    expect(Activity::query()->count())->toBe($before)
        ->and(ArPayment::query()->count())->toBe(0);

    $this->actingAs(User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]))->postJson($url, ['reference' => 'MBB-88231'])->assertForbidden();
    $foreign = Company::factory()->create();
    $this->actingAs($setup['user'])->postJson(route('finance.city-ledger.companies.payments.reference-check', ['property' => $setup['property']->code, 'company' => $foreign->id]), ['reference' => 'X'])->assertNotFound();
});

test('recording the same reference is not blocked', function () {
    $setup = refSetup();
    refFolioPayment(refCompanyFolio($setup, ['payer_company_id' => $setup['company']->id, 'number' => 'F-0031']), 'MBB-88231', attributes: ['receipt_number' => 'R-1002']);
    $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::BankTransfer, 'requires_reference' => true]);

    $result = app(RecordArPayment::class)($setup['user'], $setup['company'], new NewArPaymentData($method->id, '200.00', fake()->uuid(), null, 'MBB-88231', null));

    expect($result->isRefused())->toBeFalse();
});
