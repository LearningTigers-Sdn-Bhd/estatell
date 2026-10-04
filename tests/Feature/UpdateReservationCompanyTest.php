<?php

use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationCompany;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioType;
use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
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

test('it refuses while the master folio holds lines', function () {
    $setup = companySetup();
    [$first, $second] = Company::factory()->count(2)->for($setup['property'])->create();
    $action = app(UpdateReservationCompany::class);
    $action($setup['user'], $setup['reservation'], $first->id);
    $master = Folio::query()->where('type', FolioType::Master)->sole();
    FolioLine::factory()->create(['folio_id' => $master->id]);
    Activity::query()->delete();

    $result = $action($setup['user'], $setup['reservation'], $second->id);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_MASTER_HAS_LINES)
        ->and($setup['reservation']->fresh()->company_id)->toBe($first->id)
        ->and($master->fresh()->payer_company_id)->toBe($first->id)
        ->and(Activity::query()->count())->toBe(0);
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
});
