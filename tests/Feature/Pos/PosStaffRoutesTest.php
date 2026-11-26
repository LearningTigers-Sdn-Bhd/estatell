<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PosPostingStatus;
use App\Features\Pos;
use App\Models\MealRedemption;
use App\Models\PosApiClient;
use App\Models\PosOutlet;
use App\Models\PosPosting;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosSetup.php';

/** @return array<string, string> */
function posRoutes(array $setup): array
{
    $code = $setup['property']->code;

    return [
        'outlets' => route('settings.pos-outlets.index', $code),
        'clients' => route('settings.pos-api-clients.index', $code),
        'postings' => route('finance.pos-postings.index', $code),
        'meals' => route('restaurant.meal-check-in.index', $code),
    ];
}

function toast(TestResponse $response): ?array
{
    return $response->getSession()->get('inertia.flash_data.toast');
}

it('answers 404 on every POS page while the feature is off', function () {
    $setup = posSetup();
    Feature::for($setup['property'])->deactivate(Pos::class);

    foreach (posRoutes($setup) as $url) {
        $this->actingAs($setup['actor'])->get($url)->assertNotFound();
    }
});

it('refuses a user without the permission, and another property staff', function () {
    $setup = posSetup();
    $nobody = User::factory()->for($setup['property'])->withPermissions(['reports.view'])->create();
    $stranger = posSetup();

    foreach (posRoutes($setup) as $url) {
        $this->actingAs($nobody)->get($url)->assertForbidden();
        $this->actingAs($stranger['actor'])->get($url)->assertNotFound();
    }
});

it('lists, adds and edits outlets of the property only', function () {
    $setup = posSetup();
    $other = posSetup();
    $property = $setup['property'];

    $this->actingAs($setup['actor'])->get(posRoutes($setup)['outlets'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/pos-outlets/index')
            ->has('outlets', 1)
            ->where('outlets.0.code', $setup['outlet']->code));

    $response = $this->actingAs($setup['actor'])->post(route('settings.pos-outlets.store', $property->code), [
        'name' => 'Pool Bar', 'code' => 'pool-bar', 'default_charge_item_id' => $setup['item']->id, 'active' => true,
    ])->assertRedirect(route('settings.pos-outlets.index', $property->code));
    $created = PosOutlet::query()->where('code', 'POOL-BAR')->sole();

    expect(toast($response)['type'])->toBe('success');

    $this->actingAs($setup['actor'])->put(route('settings.pos-outlets.update', [$property->code, $created->id]), [
        'name' => 'Pool Bar 2', 'code' => 'POOL-BAR', 'default_charge_item_id' => $setup['item']->id, 'active' => false,
    ])->assertRedirect();

    expect($created->fresh()->name)->toBe('Pool Bar 2')->and($created->fresh()->active)->toBeFalse();

    $this->actingAs($setup['actor'])->put(route('settings.pos-outlets.update', [$property->code, $other['outlet']->id]), [
        'name' => 'x', 'code' => 'X', 'default_charge_item_id' => $setup['item']->id, 'active' => true,
    ])->assertNotFound();
});

it('refuses an outlet whose charge item has an exclusive tax and says why', function () {
    $setup = posSetup(inclusiveTax: false);

    $response = $this->actingAs($setup['actor'])->post(route('settings.pos-outlets.store', $setup['property']->code), [
        'name' => 'Bar', 'code' => 'BAR', 'default_charge_item_id' => $setup['item']->id, 'active' => true,
    ]);

    expect(toast($response)['type'])->toBe('error')
        ->and(toast($response)['message'])->toContain('not included')
        ->and(PosOutlet::query()->where('code', 'BAR')->exists())->toBeFalse();
});

it('adds an API client, shows its token once and the token works', function () {
    $setup = posSetup();

    $response = $this->actingAs($setup['actor'])->post(route('settings.pos-api-clients.store', $setup['property']->code), ['name' => 'Restaurant POS'])
        ->assertRedirect(route('settings.pos-api-clients.index', $setup['property']->code));
    $issued = $response->getSession()->get('inertia.flash_data.posToken');

    expect($issued['name'])->toBe('Restaurant POS');

    $this->getJson('/api/pos/v1/outlets', ['Authorization' => 'Bearer '.$issued['token']])->assertOk();

    $this->actingAs($setup['actor'])->get(posRoutes($setup)['clients'])
        ->assertInertia(fn (Assert $page) => $page->has('clients', 1)->missing('clients.0.token'));
});

it('revokes an API client, then the token stops, and another property client is not found', function () {
    $setup = posSetup();
    $other = posSetup();
    $client = PosApiClient::factory()->for($setup['property'])->withToken('est_live_revokemeaaaaaaaaaaaaaaaaaaaaaaaaaaa')->create();
    $foreign = PosApiClient::factory()->for($other['property'])->create();

    $this->actingAs($setup['actor'])->post(route('settings.pos-api-clients.revoke', [$setup['property']->code, $foreign->id]))->assertNotFound();
    $this->actingAs($setup['actor'])->post(route('settings.pos-api-clients.revoke', [$setup['property']->code, $client->id]))->assertRedirect();

    $this->getJson('/api/pos/v1/outlets', ['Authorization' => 'Bearer est_live_revokemeaaaaaaaaaaaaaaaaaaaaaaaaaaa'])->assertUnauthorized();
    expect($client->fresh()->revoked_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'api-access.token-revoked')->count())->toBe(1);
});

it('lists postings with a status filter and shows the receipt payload', function () {
    $setup = posSetup();
    $other = posSetup();
    $pending = PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id]);
    PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id, 'status' => PosPostingStatus::Posted, 'failure_reason' => null]);
    $foreign = PosPosting::factory()->create(['pos_outlet_id' => $other['outlet']->id]);
    $code = $setup['property']->code;

    $this->actingAs($setup['actor'])->get(route('finance.pos-postings.index', ['property' => $code, 'status' => 'pending_review']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/pos-postings/index')
            ->has('postings.data', 1)
            ->where('postings.data.0.id', $pending->id)
            ->where('summary.2.status', 'pending_review')
            ->where('summary.2.count', 1));

    $this->actingAs($setup['actor'])->get(route('finance.pos-postings.show', [$code, $pending->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/pos-postings/show')
            ->where('posting.rawPayload.items.0.name', 'Nasi Lemak')
            ->where('posting.canAttach', true)
            ->has('guests', 1));

    $this->actingAs($setup['actor'])->get(route('finance.pos-postings.show', [$code, $foreign->id]))->assertNotFound();
});

it('lets front desk attach or ignore a pending posting, and only with pos.resolve', function () {
    $setup = posSetup();
    $viewer = User::factory()->for($setup['property'])->withPermissions(['pos.view'])->create();
    $pending = PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id]);
    $url = route('finance.pos-postings.resolve', [$setup['property']->code, $pending->id]);

    $this->actingAs($viewer)->post($url, ['action' => 'ignore', 'note' => 'x'])->assertForbidden();

    $this->actingAs($setup['actor'])->post($url, ['action' => 'ignore'])->assertSessionHasErrors('note');

    $attached = toast($this->actingAs($setup['actor'])->post($url, ['action' => 'attach', 'reservation_unit_id' => $setup['unit']->id])->assertRedirect());
    expect($attached['type'])->toBe('success')
        ->and($pending->fresh()->status)->toBe(PosPostingStatus::Posted)
        ->and($pending->fresh()->reservation_unit_id)->toBe($setup['unit']->id);

    $again = toast($this->actingAs($setup['actor'])->post($url, ['action' => 'ignore', 'note' => 'late']));
    expect($again['type'])->toBe('error');
});

it('shows the meal check-in list, redeems a meal, refuses over the cap and undoes', function () {
    $setup = mealSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['actor'])->get(route('restaurant.meal-check-in.index', ['property' => $code, 'period' => $setup['period']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('restaurant/meal-check-in')
            ->where('periodId', $setup['period']->id)
            ->where('list.totalEntitled', 3)
            ->has('list.rows', 1));

    $body = ['reservation_unit_id' => $setup['unit']->id, 'meal_period_id' => $setup['period']->id, 'service_date' => '2026-10-14', 'guests' => 2];
    $ok = toast($this->actingAs($setup['actor'])->post(route('restaurant.meal-check-in.store', $code), $body)->assertRedirect());
    $over = toast($this->actingAs($setup['actor'])->post(route('restaurant.meal-check-in.store', $code), $body));

    expect($ok['message'])->toBe('Checked in.')
        ->and($over['type'])->toBe('error')
        ->and(MealRedemption::query()->active()->count())->toBe(1);

    $redemption = MealRedemption::query()->sole();
    $this->actingAs($setup['actor'])->delete(route('restaurant.meal-check-in.destroy', [$code, $redemption->id]))->assertRedirect();

    expect($redemption->fresh()->undone_at)->not->toBeNull();
});

it('does not redeem for another property unit or period, nor undo another property redemption', function () {
    $setup = mealSetup();
    $other = mealSetup();
    $foreign = MealRedemption::factory()->create(['reservation_unit_id' => $other['unit']->id, 'meal_period_id' => $other['period']->id]);
    $code = $setup['property']->code;

    $this->actingAs($setup['actor'])->post(route('restaurant.meal-check-in.store', $code), [
        'reservation_unit_id' => $other['unit']->id, 'meal_period_id' => $setup['period']->id, 'service_date' => '2026-10-14', 'guests' => 1,
    ])->assertNotFound();
    $this->actingAs($setup['actor'])->delete(route('restaurant.meal-check-in.destroy', [$code, $foreign->id]))->assertNotFound();
});

it('puts a non-blocking POS review note on the night audit sheet', function () {
    $setup = posSetup();
    $auditor = User::factory()->for($setup['property'])->withPermissions(['night-audit.run', 'pos.view'])->create();
    PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id, 'amount' => '100.00']);
    PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id, 'amount' => '42.50']);
    $url = route('night-audit.show', $setup['property']->code);

    $this->actingAs($auditor)->getJson($url)
        ->assertOk()
        ->assertJsonPath('posReview.message', '2 POS postings need review (MYR 142.50)')
        ->assertJsonPath('posReview.url', route('finance.pos-postings.index', ['property' => $setup['property']->code, 'status' => 'pending_review'], false));

    Feature::for($setup['property'])->deactivate(Pos::class);
    $this->actingAs($auditor)->getJson($url)->assertJsonPath('posReview', null);
});

it('gives the default roles their POS permissions', function () {
    $setup = posSetup();
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $setup['property']->id]);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);
    setPermissionsTeamId($setup['property']->id);

    expect($manager->can('pos.manage'))->toBeTrue()
        ->and($manager->can('meals.redeem'))->toBeTrue()
        ->and($frontDesk->can('pos.view'))->toBeTrue()
        ->and($frontDesk->can('pos.resolve'))->toBeTrue()
        ->and($frontDesk->can('meals.redeem'))->toBeTrue()
        ->and($frontDesk->can('pos.manage'))->toBeFalse()
        ->and($housekeeping->can('pos.view'))->toBeFalse();
});
