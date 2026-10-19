<?php

use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationCompany;
use App\Data\Reservations\NewUnitData;
use App\Enums\CompanyAccess;
use App\Enums\DefaultRoleEnum;
use App\Enums\RateDerivation;
use App\Models\Company;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A property with a Basic plan and a derived Corporate plan (-15%) on one room type with rates for 8 nights, a
 * reservation without a company and an Owner.
 *
 * @return array{user: User, property: Property, basic: RatePlan, corporate: RatePlan, roomType: RoomType, reservation: Reservation, arrival: CarbonImmutable}
 */
function negotiatedSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count(2)->for($property)->create(['room_type_id' => $roomType->id]);
    $basic = RatePlan::factory()->for($property)->create(['name' => 'Basic Rate']);
    $basic->roomTypes()->attach($roomType);
    $corporate = RatePlan::factory()->for($property)->create([
        'name' => 'Corporate',
        'pricing_basis' => $basic->pricing_basis,
        'parent_rate_plan_id' => $basic->id,
        'derivation' => RateDerivation::Percent,
        'derivation_value' => '-15',
    ]);
    $corporate->roomTypes()->attach($roomType);
    $arrival = CarbonImmutable::now()->addDays(30)->startOfDay();

    for ($night = 0; $night < 8; $night++) {
        Rate::factory()->create(['rate_plan_id' => $basic->id, 'room_type_id' => $roomType->id, 'date' => $arrival->addDays($night)->toDateString(), 'amount' => '100.00']);
    }

    return [
        'user' => $user,
        'property' => $property,
        'basic' => $basic,
        'corporate' => $corporate,
        'roomType' => $roomType,
        'reservation' => Reservation::factory()->create(['property_id' => $property->id]),
        'arrival' => $arrival,
    ];
}

function negotiatedUnit(array $setup, RatePlan $plan): NewUnitData
{
    return NewUnitData::from([
        'roomTypeId' => $setup['roomType']->id,
        'ratePlanId' => $plan->id,
        'arrivalDate' => $setup['arrival']->toDateString(),
        'departureDate' => $setup['arrival']->addDays(2)->toDateString(),
        'adults' => 2,
    ]);
}

test('AddUnit applies the desk rule of each choice', function (CompanyAccess $access, bool $atDesk, string $companyCase, bool $allowed) {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $other = Company::factory()->for($setup['property'])->create();
    $setup['corporate']->forceFill(['company_access' => $access, 'company_at_desk' => $atDesk, 'company_in_portal' => true])->save();

    if ($access === CompanyAccess::Selected) {
        $setup['corporate']->companies()->attach($company, ['at_desk' => $atDesk, 'in_portal' => true]);
    }

    $setup['reservation']->update(['company_id' => match ($companyCase) {
        'none' => null,
        'listed' => $company->id,
        'other' => $other->id,
    }]);
    $result = app(AddUnit::class)($setup['user'], $setup['reservation'], negotiatedUnit($setup, $setup['corporate']));

    expect($result->isRefused())->toBe(! $allowed)
        ->and($allowed ? $result->refusal : $result->refusal)->toBe($allowed ? null : ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED_TO_COMPANY)
        ->and($setup['reservation']->units()->count())->toBe($allowed ? 1 : 0);
})->with([
    'everyone, no company' => [CompanyAccess::Everyone, true, 'none', true],
    'everyone, a company' => [CompanyAccess::Everyone, true, 'other', true],
    'all, desk on, no company' => [CompanyAccess::All, true, 'none', false],
    'all, desk on, a company' => [CompanyAccess::All, true, 'other', true],
    'all, portal only, a company' => [CompanyAccess::All, false, 'other', false],
    'selected, no company' => [CompanyAccess::Selected, true, 'none', false],
    'selected, other company' => [CompanyAccess::Selected, true, 'other', false],
    'selected, listed with desk on' => [CompanyAccess::Selected, true, 'listed', true],
    'selected, listed with desk off' => [CompanyAccess::Selected, false, 'listed', false],
]);

test('channel ingestion skips the company rule', function () {
    $setup = negotiatedSetup();
    $setup['corporate']->forceFill(['company_access' => CompanyAccess::Selected])->save();

    expect(app(AddUnit::class)($setup['user'], $setup['reservation'], negotiatedUnit($setup, $setup['corporate']), 0, true)->isRefused())->toBeFalse();
});

test('a company change is refused while a live unit uses a plan the new company cannot use', function () {
    $setup = negotiatedSetup();
    [$first, $second, $third] = Company::factory()->count(3)->for($setup['property'])->create();
    $setup['corporate']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $setup['corporate']->companies()->attach([$first->id, $second->id]);
    $setup['reservation']->update(['company_id' => $first->id]);
    app(AddUnit::class)($setup['user'], $setup['reservation'], negotiatedUnit($setup, $setup['corporate']));
    $action = app(UpdateReservationCompany::class);

    expect($action($setup['user'], $setup['reservation'], $third->id)->refusal)->toBe(ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT)
        ->and($action($setup['user'], $setup['reservation'], null)->refusal)->toBe(ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT)
        ->and($setup['reservation']->fresh()->company_id)->toBe($first->id)
        ->and($action($setup['user'], $setup['reservation'], $second->id)->isRefused())->toBeFalse()
        ->and($setup['reservation']->fresh()->company_id)->toBe($second->id);

    $setup['corporate']->forceFill(['company_access' => CompanyAccess::All])->save();

    expect($action($setup['user'], $setup['reservation'], $third->id)->isRefused())->toBeFalse()
        ->and($action($setup['user'], $setup['reservation'], null)->refusal)->toBe(ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT);
});

test('the booking options carry the access of each plan and the companies with the desk on', function () {
    $setup = negotiatedSetup();
    [$deskOn, $deskOff] = Company::factory()->count(2)->for($setup['property'])->create();
    $setup['corporate']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $setup['corporate']->companies()->attach($deskOn, ['at_desk' => true, 'in_portal' => false]);
    $setup['corporate']->companies()->attach($deskOff, ['at_desk' => false, 'in_portal' => true]);

    $this->actingAs($setup['user'])
        ->get(route('reservations.new', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('addUnitOptions.ratePlans.0.companyAccess', 'everyone')
            ->where('addUnitOptions.ratePlans.0.companyIds', [])
            ->where('addUnitOptions.ratePlans.1.companyAccess', 'selected')
            ->where('addUnitOptions.ratePlans.1.companyIds', [$deskOn->id]));
});

test('the Rates tab is read only and lists the plans the company can use', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $other = Company::factory()->for($setup['property'])->create();
    $everyone = RatePlan::factory()->for($setup['property'])->create(['name' => 'Walk-in']);
    $all = RatePlan::factory()->for($setup['property'])->create(['name' => 'Agents', 'company_access' => CompanyAccess::All, 'company_at_desk' => false, 'company_in_portal' => true]);
    $setup['corporate']->forceFill(['company_access' => CompanyAccess::Selected])->save();
    $setup['corporate']->companies()->attach($company, ['at_desk' => true, 'in_portal' => false]);
    $notMine = RatePlan::factory()->for($setup['property'])->create(['name' => 'Other deal', 'parent_rate_plan_id' => $setup['basic']->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-5', 'company_access' => CompanyAccess::Selected]);
    $notMine->companies()->attach($other);
    $archived = RatePlan::factory()->for($setup['property'])->archived()->create(['name' => 'Old', 'company_access' => CompanyAccess::All]);
    $base = "/p/{$setup['property']->code}/finance/city-ledger/companies/{$company->id}/rates";

    $this->actingAs($setup['user'])->get($base)->assertInertia(fn (Assert $page) => $page
        ->component('finance/city-ledger/companies/show')
        ->where('tab', 'rates')
        ->has('rates', 2)
        ->where('rates.0.name', 'Corporate')
        ->where('rates.0.pricingLabel', 'Follows Basic Rate -15%')
        ->where('rates.0.atDesk', true)
        ->where('rates.0.inPortal', false)
        ->where('rates.1.name', 'Agents')
        ->where('rates.1.atDesk', false)
        ->where('rates.1.inPortal', true)
        ->where('canViewRatePlans', true));

    $this->put($base, ['rate_plan_ids' => []])->assertStatus(405);
});

test('the Rates tab needs company.view, within the property', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $base = "/p/{$setup['property']->code}/finance/city-ledger/companies/{$company->id}/rates";
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);
    $foreign = Company::factory()->create();

    $this->actingAs($housekeeping)->get($base)->assertForbidden();
    $this->actingAs($frontDesk)->get($base)->assertInertia(fn (Assert $page) => $page->where('canViewRatePlans', false));
    $this->actingAs($setup['user'])->get("/p/{$setup['property']->code}/finance/city-ledger/companies/{$foreign->id}/rates")->assertNotFound();
});
