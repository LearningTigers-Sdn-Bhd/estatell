<?php

use App\Actions\CityLedger\SyncCompanyRatePlans;
use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationCompany;
use App\Data\Reservations\NewUnitData;
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
use Spatie\Activitylog\Models\Activity;

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

test('it links derived plans to a company and logs the change', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();

    $saved = app(SyncCompanyRatePlans::class)($setup['user'], $company, [$setup['corporate']->id]);

    $activity = Activity::query()->where('event', 'company.rates_updated')->sole();

    expect($saved)->toBeTrue()
        ->and($company->ratePlans()->pluck('rate_plans.id')->all())->toBe([$setup['corporate']->id])
        ->and($activity->properties['added'])->toBe([$setup['corporate']->id])
        ->and($activity->properties['removed'])->toBe([]);

    app(SyncCompanyRatePlans::class)($setup['user'], $company, []);

    expect($company->ratePlans()->count())->toBe(0);
});

test('saving the same plans changes nothing and logs nothing', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $company->ratePlans()->attach($setup['corporate']);

    expect(app(SyncCompanyRatePlans::class)($setup['user'], $company, [$setup['corporate']->id]))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

test('it refuses a plan that is not an active derived plan of the property', function (string $kind) {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $plan = match ($kind) {
        'not derived' => $setup['basic'],
        'archived' => tap($setup['corporate'])->update(['archived_at' => now()]),
        'foreign' => RatePlan::factory()->create(['parent_rate_plan_id' => $setup['basic']->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-5']),
    };

    expect(app(SyncCompanyRatePlans::class)($setup['user'], $company, [$plan->id]))->toBeFalse()
        ->and($company->ratePlans()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with(['not derived', 'archived', 'foreign']);

test('a link to an archived plan survives a save', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $company->ratePlans()->attach($setup['corporate']);
    $setup['corporate']->update(['archived_at' => now()]);

    app(SyncCompanyRatePlans::class)($setup['user'], $company, []);

    expect($company->ratePlans()->count())->toBe(1);
});

test('AddUnit refuses a linked plan unless the reservation company is one of its companies', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $other = Company::factory()->for($setup['property'])->create();
    $company->ratePlans()->attach($setup['corporate']);
    $add = fn () => app(AddUnit::class)($setup['user'], $setup['reservation'], negotiatedUnit($setup, $setup['corporate']));

    expect($add()->refusal)->toBe(ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED_TO_COMPANY);

    $setup['reservation']->update(['company_id' => $other->id]);

    expect($add()->refusal)->toBe(ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED_TO_COMPANY)
        ->and($setup['reservation']->units()->count())->toBe(0);

    $setup['reservation']->update(['company_id' => $company->id]);

    expect($add()->isRefused())->toBeFalse()
        ->and($setup['reservation']->units()->count())->toBe(1);
});

test('an unlinked plan is open to everyone, and channel ingestion skips the company rule', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();

    expect(app(AddUnit::class)($setup['user'], $setup['reservation'], negotiatedUnit($setup, $setup['corporate']))->isRefused())->toBeFalse();

    $company->ratePlans()->attach($setup['corporate']);

    expect(app(AddUnit::class)($setup['user'], $setup['reservation'], negotiatedUnit($setup, $setup['corporate']), 0, true)->isRefused())->toBeFalse();
});

test('a company change is refused while a live unit uses a plan the new company cannot book', function () {
    $setup = negotiatedSetup();
    [$first, $second] = Company::factory()->count(2)->for($setup['property'])->create();
    $first->ratePlans()->attach($setup['corporate']);
    $second->ratePlans()->attach($setup['corporate']);
    $setup['reservation']->update(['company_id' => $first->id]);
    app(AddUnit::class)($setup['user'], $setup['reservation'], negotiatedUnit($setup, $setup['corporate']));
    $third = Company::factory()->for($setup['property'])->create();
    $action = app(UpdateReservationCompany::class);

    expect($action($setup['user'], $setup['reservation'], $third->id)->refusal)->toBe(ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT)
        ->and($action($setup['user'], $setup['reservation'], null)->refusal)->toBe(ReservationResult::REFUSAL_COMPANY_PLAN_CONFLICT)
        ->and($setup['reservation']->fresh()->company_id)->toBe($first->id)
        ->and($action($setup['user'], $setup['reservation'], $second->id)->isRefused())->toBeFalse()
        ->and($setup['reservation']->fresh()->company_id)->toBe($second->id);
});

test('the booking options carry the companies each plan is linked to', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $company->ratePlans()->attach($setup['corporate']);

    $this->actingAs($setup['user'])
        ->get(route('reservations.new', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('addUnitOptions.ratePlans.0.companyIds', [])
            ->where('addUnitOptions.ratePlans.1.companyIds', [$company->id]));
});

test('the Rates tab lists active derived plans and saves the links', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $base = "/p/{$setup['property']->code}/finance/city-ledger/companies/{$company->id}/rates";

    $this->actingAs($setup['user'])->get($base)->assertInertia(fn (Assert $page) => $page
        ->component('finance/city-ledger/companies/show')
        ->where('tab', 'rates')
        ->has('ratePlans', 1)
        ->where('ratePlans.0.name', 'Corporate')
        ->where('ratePlans.0.pricingLabel', 'Follows Basic Rate -15%')
        ->where('linkedPlanIds', []));

    $this->put($base, ['rate_plan_ids' => [$setup['corporate']->id]])->assertRedirect($base);

    expect($company->ratePlans()->pluck('rate_plans.id')->all())->toBe([$setup['corporate']->id]);

    $this->put($base, ['rate_plan_ids' => [$setup['basic']->id]])->assertRedirect($base);

    expect($company->ratePlans()->count())->toBe(1);
});

test('the Rates tab needs company.view and saving needs company.manage, within the property', function () {
    $setup = negotiatedSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $base = "/p/{$setup['property']->code}/finance/city-ledger/companies/{$company->id}/rates";
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);
    $foreign = Company::factory()->create();

    $this->actingAs($housekeeping)->get($base)->assertForbidden();
    $this->actingAs($frontDesk)->get($base)->assertOk();
    $this->put($base, ['rate_plan_ids' => []])->assertForbidden();
    $this->actingAs($setup['user'])->get("/p/{$setup['property']->code}/finance/city-ledger/companies/{$foreign->id}/rates")->assertNotFound();
});
