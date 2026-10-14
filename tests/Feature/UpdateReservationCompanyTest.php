<?php

use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationCompany;
use App\Enums\ArInvoiceStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioType;
use App\Enums\ReservationStatus;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, property: Property, reservation: Reservation}
 */
function companySetup(DefaultRoleEnum $role = DefaultRoleEnum::FrontDesk): array
{
    $user = User::factory()->withRole($role)->create();
    $reservation = Reservation::factory()->create(['property_id' => $user->property_id]);
    ReservationUnit::factory()->for($reservation)->create();

    return ['user' => $user, 'property' => $user->property, 'reservation' => $reservation];
}

test('it sets the company, creates the master folio and logs the change', function () {
    $setup = companySetup();
    $company = Company::factory()->for($setup['property'])->create();

    $result = app(UpdateReservationCompany::class)($setup['user'], $setup['reservation'], $company->id);

    $master = Folio::query()->where('reservation_id', $setup['reservation']->id)->where('type', FolioType::Master)->sole();
    $activity = Activity::query()->where('event', 'reservation.company_updated')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['reservation']->fresh()->company_id)->toBe($company->id)
        ->and($master->payer_company_id)->toBe($company->id)
        ->and($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->properties['old']['company_id'])->toBeNull()
        ->and($activity->properties['new']['company_id'])->toBe($company->id);
});

test('an empty master folio payer follows the new company and clearing removes it', function () {
    $setup = companySetup();
    [$first, $second] = Company::factory()->count(2)->for($setup['property'])->create();
    $action = app(UpdateReservationCompany::class);

    $action($setup['user'], $setup['reservation'], $first->id);
    $action($setup['user'], $setup['reservation'], $second->id);
    $master = Folio::query()->where('type', FolioType::Master)->sole();

    expect($master->fresh()->payer_company_id)->toBe($second->id);

    $action($setup['user'], $setup['reservation'], null);

    expect($setup['reservation']->fresh()->company_id)->toBeNull()
        ->and($master->fresh()->payer_company_id)->toBeNull()
        ->and(Folio::query()->where('type', FolioType::Master)->count())->toBe(1);
});

/**
 * A Completed payment of the amount on the folio, or a refund of `$refundOf`, each with its ledger line.
 */
function companyMoney(Folio $folio, string $amount, ?Payment $refundOf = null): Payment
{
    $line = FolioLine::factory()->payment($amount)->create(['folio_id' => $folio->id]);

    return Payment::factory()->create([
        'folio_id' => $folio->id,
        'amount' => $amount,
        'refund_of_id' => $refundOf?->id,
        'receipt_number' => $refundOf === null ? 'R-'.fake()->unique()->numerify('######') : null,
        'folio_line_id' => $line->id,
    ]);
}

test('a master folio with only charges moves to the new company with its charges', function () {
    $setup = companySetup();
    [$first, $second] = Company::factory()->count(2)->for($setup['property'])->create();
    $action = app(UpdateReservationCompany::class);
    $action($setup['user'], $setup['reservation'], $first->id);
    $master = Folio::query()->where('type', FolioType::Master)->sole();
    $line = FolioLine::factory()->create(['folio_id' => $master->id]);

    $result = $action($setup['user'], $setup['reservation'], $second->id);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['reservation']->fresh()->company_id)->toBe($second->id)
        ->and($master->fresh()->payer_company_id)->toBe($second->id)
        ->and($line->fresh()->folio_id)->toBe($master->id);
});

test('it refuses while the master folio holds money, and a fully refunded payment does not block', function () {
    $setup = companySetup();
    [$first, $second] = Company::factory()->count(2)->for($setup['property'])->create();
    $action = app(UpdateReservationCompany::class);
    $action($setup['user'], $setup['reservation'], $first->id);
    $master = Folio::query()->where('type', FolioType::Master)->sole();
    $payment = companyMoney($master, '100.00');
    companyMoney($master, '30.00', $payment);
    Activity::query()->delete();

    $result = $action($setup['user'], $setup['reservation'], $second->id);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_MASTER_HAS_PAYMENTS)
        ->and($result->detail)->toBe('70.00')
        ->and($setup['reservation']->fresh()->company_id)->toBe($first->id)
        ->and($master->fresh()->payer_company_id)->toBe($first->id)
        ->and(Activity::query()->count())->toBe(0);

    companyMoney($master, '70.00', $payment);

    expect($action($setup['user'], $setup['reservation'], $second->id)->isRefused())->toBeFalse()
        ->and($master->fresh()->payer_company_id)->toBe($second->id);
});

test('it refuses while the master folio has an AR invoice that is not void, and clearing the company follows the same rule', function () {
    $setup = companySetup();
    [$first, $second] = Company::factory()->count(2)->for($setup['property'])->create();
    $action = app(UpdateReservationCompany::class);
    $action($setup['user'], $setup['reservation'], $first->id);
    $master = Folio::query()->where('type', FolioType::Master)->sole();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $first->id, 'folio_id' => $master->id, 'number' => 'F-0031']);

    $result = $action($setup['user'], $setup['reservation'], $second->id);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_MASTER_HAS_AR_INVOICE)
        ->and($result->detail)->toBe('F-0031')
        ->and($action($setup['user'], $setup['reservation'], null)->refusal)->toBe(ReservationResult::REFUSAL_MASTER_HAS_AR_INVOICE)
        ->and($master->fresh()->payer_company_id)->toBe($first->id);

    $invoice->update(['status' => ArInvoiceStatus::Void]);

    expect($action($setup['user'], $setup['reservation'], $second->id)->isRefused())->toBeFalse();
});

test('it refuses a suspended company and one from another property', function (string $kind) {
    $setup = companySetup();
    $company = $kind === 'suspended'
        ? Company::factory()->for($setup['property'])->suspended()->create()
        : Company::factory()->create();

    $result = app(UpdateReservationCompany::class)($setup['user'], $setup['reservation'], $company->id);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_COMPANY_NOT_AVAILABLE)
        ->and($setup['reservation']->fresh()->company_id)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
})->with(['suspended', 'foreign']);

test('a reservation keeps its suspended company and saving it again is not a change', function () {
    $setup = companySetup();
    $company = Company::factory()->for($setup['property'])->create();
    $setup['reservation']->update(['company_id' => $company->id]);
    $company->update(['status' => 'suspended']);

    $result = app(UpdateReservationCompany::class)($setup['user'], $setup['reservation'], $company->id);

    expect($result->isRefused())->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);
});

test('it refuses a closed reservation', function (ReservationStatus $status) {
    $setup = companySetup();
    $setup['reservation']->forceFill(['status' => $status])->save();
    $company = Company::factory()->for($setup['property'])->create();

    $result = app(UpdateReservationCompany::class)($setup['user'], $setup['reservation'], $company->id);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_RESERVATION_CLOSED)
        ->and($setup['reservation']->fresh()->company_id)->toBeNull();
})->with([ReservationStatus::Cancelled, ReservationStatus::Completed]);

test('the company route needs reservations.manage and company.view', function (DefaultRoleEnum $role) {
    $setup = companySetup($role);
    $company = Company::factory()->for($setup['property'])->create();

    $this->actingAs($setup['user'])
        ->put(route('reservations.company.update', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]), ['company_id' => $company->id])
        ->assertForbidden();

    expect($setup['reservation']->fresh()->company_id)->toBeNull();
})->with([DefaultRoleEnum::Finance]);

test('staff change the company and a refusal goes back as a field error', function () {
    $setup = companySetup();
    $company = Company::factory()->for($setup['property'])->create();
    $suspended = Company::factory()->for($setup['property'])->suspended()->create();
    $url = route('reservations.company.update', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->put($url, ['company_id' => $company->id])->assertRedirect()->assertSessionHasNoErrors();

    expect($setup['reservation']->fresh()->company_id)->toBe($company->id);

    $this->put($url, ['company_id' => $suspended->id])->assertSessionHasErrors('company_id');

    expect($setup['reservation']->fresh()->company_id)->toBe($company->id);

    $master = Folio::query()->where('type', FolioType::Master)->sole();
    companyMoney($master, '1000.00');
    $other = Company::factory()->for($setup['property'])->create();
    $currency = PropertySetting::factory()->for($setup['property'])->create()->refresh()->currency->getCurrencyCode();

    $this->put($url, ['company_id' => $other->id])->assertSessionHasErrors(['company_id' => 'Refund the '.$currency.' 1,000.00 payment on the Master folio first, then change the company.']);
});
