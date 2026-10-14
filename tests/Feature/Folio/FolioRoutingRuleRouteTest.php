<?php

use App\Actions\Folio\CreateFolio;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioType;
use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * A user with the role, a reservation of 2 checked-in units, a Unit folio for the first unit, an Open third-party
 * folio paid by a guest, and a Room code.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit1: ReservationUnit, unit2: ReservationUnit, unitFolio: Folio, agent: Folio, guest: Guest, room: TransactionCode}
 */
function rrSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $units = ReservationUnit::factory()->checkedIn()->count(2)->create(['reservation_id' => $reservation->id]);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Blue Reef Dive']);

    return [
        'user' => $user,
        'property' => $property,
        'reservation' => $reservation,
        'unit1' => $units[0],
        'unit2' => $units[1],
        'unitFolio' => app(CreateFolio::class)($reservation, $units[0]),
        'agent' => app(CreateFolio::class)->thirdParty($reservation, $guest),
        'guest' => $guest,
        'room' => TransactionCode::factory()->create(['property_id' => $property->id, 'category' => TransactionCategory::Room]),
    ];
}

/**
 * @param  array<string, mixed>  $parameters
 */
function rrUrl(array $setup, string $route, array $parameters = []): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, ...$parameters]);
}

test('folio.transfer roles can add a rule and others cannot', function (DefaultRoleEnum $role, int $status) {
    $setup = rrSetup($role);

    $this->actingAs($setup['user'])
        ->post(rrUrl($setup, 'reservations.routing-rules.store'), ['charges' => 'all', 'target_folio_id' => $setup['agent']->id])
        ->assertStatus($status);
})->with([
    [DefaultRoleEnum::Owner, 302],
    [DefaultRoleEnum::Manager, 302],
    [DefaultRoleEnum::Finance, 302],
    [DefaultRoleEnum::FrontDesk, 403],
]);

test('front desk cannot create a third-party folio or remove a rule', function () {
    $setup = rrSetup(DefaultRoleEnum::FrontDesk);
    $rule = FolioRoutingRule::factory()->toFolio($setup['agent'])->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->post(rrUrl($setup, 'reservations.folios.third-party.store'), ['payer_guest_id' => $setup['guest']->id])->assertForbidden();
    $this->actingAs($setup['user'])->delete(rrUrl($setup, 'reservations.routing-rules.destroy', ['routingRule' => $rule->id]))->assertForbidden();
});

test('another property, a target folio of another reservation and a rule of another reservation are 404', function () {
    $setup = rrSetup();
    $other = rrSetup();
    $otherRule = FolioRoutingRule::factory()->toFolio($other['agent'])->create(['reservation_id' => $other['reservation']->id]);
    $sameProperty = Folio::factory()->create(['property_id' => $setup['property']->id]);
    $sameRule = FolioRoutingRule::factory()->create(['reservation_id' => $sameProperty->reservation_id, 'target_folio_id' => $sameProperty->id]);

    $this->actingAs($setup['user'])->post(rrUrl($other, 'reservations.routing-rules.store'), ['charges' => 'all', 'target_folio_id' => $other['agent']->id])->assertNotFound();
    $this->actingAs($setup['user'])->post(rrUrl($setup, 'reservations.routing-rules.store'), ['charges' => 'all', 'target_folio_id' => $sameProperty->id])->assertNotFound();
    $this->actingAs($setup['user'])->delete(rrUrl($setup, 'reservations.routing-rules.destroy', ['routingRule' => $sameRule->id]))->assertNotFound();
    $this->actingAs($setup['user'])->delete(rrUrl($setup, 'reservations.routing-rules.destroy', ['routingRule' => $otherRule->id]))->assertNotFound();

    expect(FolioRoutingRule::query()->count())->toBe(2);
});

test('a third-party folio is created for the guest, logged and opened on the folio tab', function () {
    $setup = rrSetup();
    $payer = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Tan Travel']);

    $response = $this->actingAs($setup['user'])->post(rrUrl($setup, 'reservations.folios.third-party.store'), ['payer_guest_id' => $payer->id]);

    $folio = Folio::query()->where('payer_guest_id', $payer->id)->sole();

    expect($folio->type)->toBe(FolioType::ThirdParty)
        ->and($folio->reservation_id)->toBe($setup['reservation']->id)
        ->and(Activity::query()->where('event', 'folio.third_party_created')->where('causer_id', $setup['user']->id)->sole()->properties['folio_id'])->toBe($folio->id);

    $response->assertRedirect(rrUrl($setup, 'reservations.folio', ['folio' => $folio->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Folio '.$folio->number.' created for Tan Travel.']);
});

test('a third-party folio is created for an Active company, logged and named after it', function () {
    $setup = rrSetup();
    $company = Company::factory()->for($setup['property'])->create(['name' => 'Borneo Travel']);

    $response = $this->actingAs($setup['user'])->post(rrUrl($setup, 'reservations.folios.third-party.store'), ['payer_company_id' => $company->id]);

    $folio = Folio::query()->where('payer_company_id', $company->id)->sole();

    expect($folio->type)->toBe(FolioType::ThirdParty)
        ->and($folio->payer_guest_id)->toBeNull()
        ->and(Activity::query()->where('event', 'folio.third_party_created')->latest('id')->first()->properties['payer_company_id'])->toBe($company->id);

    $response->assertRedirect(rrUrl($setup, 'reservations.folio', ['folio' => $folio->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Folio '.$folio->number.' created for Borneo Travel.']);
});

test('a third-party folio needs exactly one payer, and a company must be Active and of the property', function (Closure $payload) {
    $setup = rrSetup();

    $this->actingAs($setup['user'])
        ->post(rrUrl($setup, 'reservations.folios.third-party.store'), $payload($setup))
        ->assertSessionHasErrors();

    expect(Folio::query()->where('type', FolioType::ThirdParty)->count())->toBe(1);
})->with([
    'no payer' => [fn (array $setup) => []],
    'guest and company' => [fn (array $setup) => ['payer_guest_id' => $setup['guest']->id, 'payer_company_id' => Company::factory()->for($setup['property'])->create()->id]],
    'suspended company' => [fn (array $setup) => ['payer_company_id' => Company::factory()->for($setup['property'])->suspended()->create()->id]],
    'company of another property' => [fn (array $setup) => ['payer_company_id' => Company::factory()->create()->id]],
]);

test('a company payer needs company.view', function () {
    $setup = rrSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $staff = User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'folio.transfer'])->create();

    $this->actingAs($staff)->post(rrUrl($setup, 'reservations.folios.third-party.store'), ['payer_company_id' => $company->id])
        ->assertSessionHasErrors('payer_company_id');
    $this->post(rrUrl($setup, 'reservations.folios.third-party.store'), ['payer_guest_id' => $setup['guest']->id])
        ->assertSessionHasNoErrors();
});

test('a third-party payer from another property, merged or anonymized is a 404', function (Closure $payer) {
    $setup = rrSetup();

    $this->actingAs($setup['user'])
        ->post(rrUrl($setup, 'reservations.folios.third-party.store'), ['payer_guest_id' => $payer($setup['property'])->id])
        ->assertNotFound();

    expect(Folio::query()->where('type', FolioType::ThirdParty)->count())->toBe(1);
})->with([
    'another property' => [fn (Property $property) => Guest::factory()->create()],
    'merged' => [fn (Property $property) => Guest::factory()->create(['property_id' => $property->id, 'merged_into_id' => Guest::factory()->create(['property_id' => $property->id])->id])],
    'anonymized' => [fn (Property $property) => Guest::factory()->create(['property_id' => $property->id, 'anonymized_at' => now()])],
]);

test('a Manual rule is saved, logged and routes the next charge', function () {
    $setup = rrSetup();

    $this->actingAs($setup['user'])
        ->post(rrUrl($setup, 'reservations.routing-rules.store'), ['reservation_unit_id' => $setup['unit1']->id, 'charges' => 'category', 'category' => 'room', 'target_folio_id' => $setup['agent']->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Routing rule added. Later charges go to folio '.$setup['agent']->number.'.']);

    $rule = FolioRoutingRule::query()->sole();

    expect($rule->source)->toBe(RoutingRuleSource::Manual)
        ->and($rule->category)->toBe(TransactionCategory::Room)
        ->and($rule->reservation_unit_id)->toBe($setup['unit1']->id)
        ->and(app(FolioRouter::class)->targetFor($setup['unit1'], $setup['room'])?->id)->toBe($setup['agent']->id)
        ->and(app(FolioRouter::class)->targetFor($setup['unit2'], $setup['room']))->toBeNull()
        ->and(Activity::query()->where('event', 'routing_rule.created')->sole()->properties['routing_rule_id'])->toBe($rule->id);
});

test('a rule by charge code or for all charges keeps only the chosen selector', function () {
    $setup = rrSetup();

    $this->actingAs($setup['user'])
        ->post(rrUrl($setup, 'reservations.routing-rules.store'), ['charges' => 'code', 'transaction_code_id' => $setup['room']->id, 'category' => 'fee', 'target_folio_id' => $setup['agent']->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($setup['user'])
        ->post(rrUrl($setup, 'reservations.routing-rules.store'), ['charges' => 'all', 'category' => 'fee', 'transaction_code_id' => $setup['room']->id, 'target_folio_id' => $setup['agent']->id])
        ->assertSessionHasNoErrors();

    expect(FolioRoutingRule::query()->orderBy('id')->get(['category', 'transaction_code_id'])->map->only(['category', 'transaction_code_id'])->all())
        ->toBe([['category' => null, 'transaction_code_id' => $setup['room']->id], ['category' => null, 'transaction_code_id' => null]]);
});

test('a refused rule shows on its field and writes and logs nothing', function (Closure $payload, string $field) {
    $setup = rrSetup();
    FolioRoutingRule::factory()->fromBillingMode()->forCategory(TransactionCategory::Room)->toFolio($setup['unitFolio'])->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])
        ->post(rrUrl($setup, 'reservations.routing-rules.store'), $payload($setup))
        ->assertSessionHasErrors($field);

    expect(FolioRoutingRule::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'routing_rule.created')->exists())->toBeFalse();
})->with([
    'a closed target' => [function (array $setup) {
        $closed = Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

        return ['charges' => 'all', 'target_folio_id' => $closed->id];
    }, 'target_folio_id'],
    'a voided target' => [function (array $setup) {
        $voided = Folio::factory()->thirdParty()->voided()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

        return ['charges' => 'all', 'target_folio_id' => $voided->id];
    }, 'target_folio_id'],
    'the same selectors as a billing mode rule' => [fn (array $setup) => ['charges' => 'category', 'category' => 'room', 'target_folio_id' => $setup['agent']->id], 'form'],
    'a unit of another reservation' => [fn (array $setup) => ['reservation_unit_id' => ReservationUnit::factory()->create()->id, 'charges' => 'all', 'target_folio_id' => $setup['agent']->id], 'reservation_unit_id'],
    'a code of another property' => [fn (array $setup) => ['charges' => 'code', 'transaction_code_id' => TransactionCode::factory()->create()->id, 'target_folio_id' => $setup['agent']->id], 'transaction_code_id'],
    'a category that never routes' => [fn (array $setup) => ['charges' => 'category', 'category' => 'tax', 'target_folio_id' => $setup['agent']->id], 'category'],
    'no category' => [fn (array $setup) => ['charges' => 'category', 'target_folio_id' => $setup['agent']->id], 'category'],
]);

test('removing a Manual rule deletes and logs it', function () {
    $setup = rrSetup();
    $rule = FolioRoutingRule::factory()->toFolio($setup['agent'])->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])
        ->delete(rrUrl($setup, 'reservations.routing-rules.destroy', ['routingRule' => $rule->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Routing rule removed.']);

    expect(FolioRoutingRule::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'routing_rule.removed')->sole()->properties['routing_rule_id'])->toBe($rule->id);
});

test('a billing mode rule cannot be removed', function () {
    $setup = rrSetup();
    $rule = FolioRoutingRule::factory()->fromBillingMode()->toFolio($setup['agent'])->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])
        ->delete(rrUrl($setup, 'reservations.routing-rules.destroy', ['routingRule' => $rule->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This rule comes from the billing mode. Change the billing mode to change it.']);

    expect($rule->fresh())->not->toBeNull()
        ->and(Activity::query()->where('event', 'routing_rule.removed')->exists())->toBeFalse();
});

/**
 * The folio tab as a partial request for the routing rules only.
 */
function rrSheet(array $setup): TestResponse
{
    return test()->actingAs($setup['user'])->get(rrUrl($setup, 'reservations.folio'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'reservations/show',
        'X-Inertia-Partial-Data' => 'routingRules',
        'X-Inertia-Version' => Inertia\Inertia::getVersion(),
    ]);
}

test('the routing rules load on request only, most specific first, with open folios as targets', function () {
    $setup = rrSetup();
    $closed = Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->forUnit($setup['unit1'])->toFolio($setup['agent'])->create(['reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->forCategory(TransactionCategory::Room)->toFolio($setup['agent'])->fromBillingMode()->create(['reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->forCode($setup['room'])->toFolio($setup['unitFolio'])->create(['reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get(rrUrl($setup, 'reservations.folio'))->assertInertia(fn (AssertableInertia $page) => $page
        ->missing('routingRules')
        ->where('canManageRouting', true));

    $response = rrSheet($setup)->assertOk();
    $rules = collect($response->json('props.routingRules.rules'));

    expect($rules->pluck('charges')->all())->toBe([$setup['room']->code.' '.$setup['room']->name, 'Room', 'All charges'])
        ->and($rules->pluck('unit')->all())->toBe(['All units', 'All units', $rules[2]['unit']])
        ->and($rules[2]['unit'])->toStartWith('Unit ')
        ->and($rules->pluck('canRemove')->all())->toBe([true, false, true])
        ->and($rules[1]['target'])->toBe('Third party · Blue Reef Dive')
        ->and(collect($response->json('props.routingRules.targets'))->pluck('id')->all())->toBe([$setup['unitFolio']->id, $setup['agent']->id])
        ->and(collect($response->json('props.routingRules.targets'))->pluck('id'))->not->toContain($closed->id)
        ->and(collect($response->json('props.routingRules.categories'))->pluck('value')->all())->toBe(['room', 'food_and_beverage', 'laundry', 'minibar', 'transport', 'fee', 'other']);
});

test('a user without folio.transfer gets no routing rules', function () {
    $setup = rrSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($setup['user'])->get(rrUrl($setup, 'reservations.folio'))->assertInertia(fn (AssertableInertia $page) => $page->where('canManageRouting', false));

    rrSheet($setup)->assertOk()->assertJsonPath('props.routingRules', null);
});

test('the routing rules need a constant number of queries with lazy loading prevented', function () {
    $setup = rrSetup();

    $queries = function (int $added) use ($setup): int {
        foreach (range(1, $added) as $ignored) {
            $folio = app(CreateFolio::class)->thirdParty($setup['reservation'], Guest::factory()->create(['property_id' => $setup['property']->id]));
            FolioRoutingRule::factory()->forCode(TransactionCode::factory()->create(['property_id' => $setup['property']->id]))->toFolio($folio)->create(['reservation_id' => $setup['reservation']->id]);
        }

        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        rrSheet($setup)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $count;
    };

    test()->actingAs($setup['user'])->get(rrUrl($setup, 'reservations.folio'))->assertOk();

    expect($queries(4))->toBe($queries(1));
});
