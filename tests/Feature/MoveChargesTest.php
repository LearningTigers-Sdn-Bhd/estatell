<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\CreateRoutingRule;
use App\Actions\Folio\MoveCharges;
use App\Actions\Folio\MoveChargesResult;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\RoutingRuleResult;
use App\Data\Folio\FolioRowData;
use App\Data\Folio\NewChargeData;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\RoutingRuleSource;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\MoveChargesPreview;
use App\Services\Folio\ScheduledFolioRows;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner of a live property with an open business date (2026-10-10), the room revenue code, a Master folio and two
 * checked-in units with 2 scheduled nights each (11 and 12 Oct, 100.00 a night) and a Unit folio each. No tax.
 *
 * @return array{user: User, property: Property, reservation: Reservation, room: TransactionCode, master: Folio, units: list<ReservationUnit>, folios: list<Folio>}
 */
function mcSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $units = [];
    $folios = [];

    foreach ([0, 1] as $index) {
        $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create(['arrival_date' => '2026-10-11', 'departure_date' => '2026-10-13']);
        UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '100.00', 'amount' => '100.00']);
        $units[$index] = $unit;
        $folios[$index] = app(CreateFolio::class)($reservation, $unit);
    }

    return [
        'user' => $user,
        'property' => $property,
        'reservation' => $reservation,
        'room' => $room,
        'master' => Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]),
        'units' => $units,
        'folios' => $folios,
    ];
}

function mcTax(array $setup, string $rate = '8.00'): void
{
    Tax::factory()->for($setup['property'])->create(['rate' => $rate, 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false])
        ->applicableTransactionCodes()->attach($setup['room']);
}

/** Posts a charge on a given folio for a unit and returns the charge line (the tax children follow it). */
function mcPost(array $setup, int $unit, Folio $folio, string $amount = '100.00', ?TransactionCode $code = null, ?string $description = null): FolioLine
{
    return app(PostCharge::class)($setup['user'], $setup['units'][$unit], NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => ($code ?? $setup['room'])->id,
        'description' => $description,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
        'folioId' => $folio->id,
    ]))->line();
}

/**
 * @param  list<FolioLine>  $lines
 * @param  array<int, string>  $schedules  Scope by unit id.
 * @return array<string, mixed>
 */
function mcBody(Folio $target, array $lines = [], array $schedules = []): array
{
    return [
        'target_folio_id' => $target->id,
        'line_ids' => array_map(fn (FolioLine $line): int => $line->id, $lines),
        'schedules' => collect($schedules)->map(fn (string $scope, int $unitId): array => ['unit_id' => $unitId, 'scope' => $scope])->values()->all(),
    ];
}

function mcUrl(array $setup, string $route): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);
}

/** @param array<string, mixed> $body */
function mcPreview(array $setup, array $body, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $setup['user'])->postJson(mcUrl($setup, 'reservations.folio.move.preview'), $body);
}

/** @param array<string, mixed> $body */
function mcStore(array $setup, array $body, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $setup['user'])->post(mcUrl($setup, 'reservations.folio.move.store'), $body);
}

function mcBalance(Folio $folio): string
{
    return app(FolioBalance::class)->forFolio($folio);
}

/** @return list<array{target: int|null, unit: int, date: string, code: TransactionCode, rows: list<FolioRowData>}> */
function mcBlocks(array $setup): array
{
    $labels = app(MoveChargesPreview::class)->unitLabels($setup['reservation']);

    return app(ScheduledFolioRows::class)->forReservation($setup['property']->loadMissing('settings'), $setup['reservation']->loadMissing('booker'), $labels);
}

/** The Scheduled add-on of a unit on a future date, with its own charge item and code. */
function mcAddOn(array $setup, int $unit, string $name = 'Island trip'): UnitAddOn
{
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => $name]);

    return UnitAddOn::factory()->create(['reservation_unit_id' => $setup['units'][$unit]->id, 'charge_item_id' => $item->id, 'service_date' => '2026-10-12', 'unit_amount' => '25.00']);
}

test('charges from several folios move in one transfer group and the balances match the preview', function () {
    $setup = mcSetup();
    mcTax($setup);
    $a = mcPost($setup, 0, $setup['folios'][0], '100.00');
    $b = mcPost($setup, 1, $setup['folios'][1], '50.00');
    $body = mcBody($setup['master'], [$a, $b]);

    $preview = mcPreview($setup, $body)->assertOk()->json();
    $figures = collect($preview['balances'])->keyBy('folioId');

    expect($figures[$setup['master']->id])->toMatchArray(['before' => '0.00', 'after' => '162.00'])
        ->and($figures[$setup['folios'][0]->id])->toMatchArray(['before' => '108.00', 'after' => '0.00'])
        ->and($figures[$setup['folios'][1]->id])->toMatchArray(['before' => '54.00', 'after' => '0.00'])
        ->and($preview['chargeCount'])->toBe(2)
        ->and($preview['amount'])->toBe('162.00')
        ->and(FolioLine::query()->whereNotNull('transfer_group_id')->count())->toBe(0);

    mcStore($setup, $body)
        ->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['master']->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 charges moved to Master.']);

    expect(mcBalance($setup['master']))->toBe($figures[$setup['master']->id]['after'])
        ->and(mcBalance($setup['folios'][0]))->toBe($figures[$setup['folios'][0]->id]['after'])
        ->and(mcBalance($setup['folios'][1]))->toBe($figures[$setup['folios'][1]->id]['after'])
        ->and(FolioLine::query()->whereNotNull('transfer_group_id')->distinct()->pluck('transfer_group_id'))->toHaveCount(1)
        ->and(FolioLine::query()->where('folio_id', $setup['master']->id)->whereNull('parent_line_id')->count())->toBe(2);

    $log = Activity::query()->where('event', 'folio.charges_moved')->sole();

    expect(Activity::query()->where('event', 'folio.lines_transferred')->count())->toBe(0)
        ->and($log->description)->toBe('Moved 2 charges (MYR 162.00) to Master from 2 folios.')
        ->and($log->properties['charge_count'])->toBe(2)
        ->and($log->properties['to_folio_id'])->toBe($setup['master']->id)
        ->and($log->properties['from_folio_ids'])->toEqualCanonicalizing([$setup['folios'][0]->id, $setup['folios'][1]->id]);
});

test('one bad line refuses the whole move and writes nothing, and a replay is refused', function () {
    $setup = mcSetup();
    mcTax($setup);
    $good = mcPost($setup, 0, $setup['folios'][0]);
    $child = $good->children()->firstOrFail();
    $count = FolioLine::query()->count();
    $logged = Activity::query()->count();

    mcStore($setup, mcBody($setup['master'], [$good, $child]))->assertSessionHasErrors('form');

    expect(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->count())->toBe($logged);

    mcStore($setup, mcBody($setup['master'], [$good]))->assertSessionHasNoErrors();
    $count = FolioLine::query()->count();

    mcStore($setup, mcBody($setup['master'], [$good]))->assertSessionHasErrors('form');

    expect(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->where('event', 'folio.charges_moved')->count())->toBe(1);
});

test('closed and voided folios and payment lines are not offered', function () {
    $setup = mcSetup();
    $charge = mcPost($setup, 0, $setup['folios'][0]);
    $payment = Payment::factory()->create(['folio_id' => $setup['folios'][0]->id]);
    $closed = Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $voided = Folio::factory()->thirdParty()->voided()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $page = app(MoveChargesPreview::class)->page($setup['property'], $setup['reservation'], $setup['master'], [$charge->id, $payment->folio_line_id], '/folio');
    $folios = collect($page->folios);

    expect($folios->pluck('id')->all())->toEqualCanonicalizing([$setup['master']->id, $setup['folios'][0]->id, $setup['folios'][1]->id])
        ->and($folios->pluck('id')->all())->not->toContain($closed->id, $voided->id)
        ->and($folios->firstWhere('id', $setup['folios'][0]->id)->charges)->toHaveCount(1)
        ->and($folios->firstWhere('id', $setup['folios'][0]->id)->charges[0]->lineId)->toBe($charge->id)
        ->and($page->lineIds)->toBe([$charge->id]);
});

test('charge room nights creates the unit and Room rule and the scheduled nights show on the target', function () {
    $setup = mcSetup();
    $body = mcBody($setup['master'], [], [$setup['units'][0]->id => 'room']);
    $currency = $setup['property']->settings->currency->getCurrencyCode();

    $preview = mcPreview($setup, $body)->assertOk()->json();
    $first = collect($preview['schedules'])->firstWhere('unitId', $setup['units'][0]->id);

    expect(collect($preview['schedules']))->toHaveCount(2)
        ->and($first['fromFolioId'])->toBe($setup['folios'][0]->id)
        ->and($first['choices'][0])->toMatchArray(['scope' => 'room', 'title' => 'Charge room nights to Master', 'detail' => '11–12 Oct · 2 nights · '.$currency.' 200.00', 'amount' => '200.00'])
        ->and($first['choices'][1]['scope'])->toBe('all')
        ->and($preview['scheduleCount'])->toBe(1)
        ->and($preview['projectedBefore'])->toBe('0.00')
        ->and($preview['projectedAfter'])->toBe('200.00')
        ->and($preview['incoming'][0])->toMatchArray(['unitId' => $setup['units'][0]->id, 'amount' => '200.00']);

    mcStore($setup, $body)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Scheduled nights of 1 room will be charged to Master.']);

    $rule = FolioRoutingRule::query()->sole();
    $blocks = collect(mcBlocks($setup));

    expect($rule->reservation_unit_id)->toBe($setup['units'][0]->id)
        ->and($rule->category)->toBe(TransactionCategory::Room)
        ->and($rule->transaction_code_id)->toBeNull()
        ->and($rule->source)->toBe(RoutingRuleSource::Manual)
        ->and($rule->target_folio_id)->toBe($setup['master']->id)
        ->and($blocks->where('unit', $setup['units'][0]->id)->pluck('target')->unique()->all())->toBe([$setup['master']->id])
        ->and($blocks->where('unit', $setup['units'][1]->id)->pluck('target')->unique()->all())->toBe([$setup['folios'][1]->id])
        ->and(Activity::query()->where('event', 'folio.charges_moved')->sole()->description)->toBe('Scheduled room nights of 1 room now go to Master.')
        ->and(Activity::query()->where('event', 'routing_rule.created')->count())->toBe(0);
});

test('charge everything creates both rules and wins over a BillingMode Room rule', function () {
    $setup = mcSetup();
    $agent = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $billing = FolioRoutingRule::factory()->create(['reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => null, 'category' => TransactionCategory::Room, 'transaction_code_id' => null, 'target_folio_id' => $agent->id, 'source' => RoutingRuleSource::BillingMode]);
    mcAddOn($setup, 0);

    expect(collect(mcBlocks($setup))->where('unit', $setup['units'][0]->id)->first(fn ($block) => $block['code']->category === TransactionCategory::Room)['target'])->toBe($agent->id);

    $body = mcBody($setup['master'], [], [$setup['units'][0]->id => 'all']);
    $choices = collect(mcPreview($setup, $body)->assertOk()->json('schedules'))->firstWhere('unitId', $setup['units'][0]->id)['choices'];

    expect($choices[1]['detail'])->toContain('Room nights, Island trip 12 Oct and any later charge');

    mcStore($setup, $body)->assertSessionHasNoErrors();

    $manual = FolioRoutingRule::query()->where('source', RoutingRuleSource::Manual)->get();
    $blocks = collect(mcBlocks($setup))->where('unit', $setup['units'][0]->id);

    expect($manual)->toHaveCount(2)
        ->and($manual->pluck('category')->map(fn ($category) => $category?->value)->all())->toEqualCanonicalizing(['room', null])
        ->and($manual->pluck('target_folio_id')->unique()->all())->toBe([$setup['master']->id])
        ->and($blocks->pluck('target')->unique()->all())->toBe([$setup['master']->id])
        ->and($billing->fresh()->target_folio_id)->toBe($agent->id);
});

test('a row kept by a more specific rule stays and the preview says so', function () {
    $setup = mcSetup();
    $agent = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $addOn = mcAddOn($setup, 0, 'Island trip');
    FolioRoutingRule::factory()->create(['reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => null, 'category' => null, 'transaction_code_id' => $addOn->chargeItem->transaction_code_id, 'target_folio_id' => $agent->id, 'source' => RoutingRuleSource::Manual]);

    $preview = mcPreview($setup, mcBody($setup['master'], [], [$setup['units'][0]->id => 'all']))->assertOk()->json();

    expect($preview['notes'])->toBe(['Island trip 12 Oct stays on Third party: a rule sends Island trip charges there.'])
        ->and($preview['incoming'])->toHaveCount(1)
        ->and($preview['incoming'][0]['text'])->toStartWith('Room nights');
});

test('posted room charges ticked with no schedule choice say the nights stay behind', function () {
    $setup = mcSetup();
    $room = mcPost($setup, 0, $setup['folios'][0]);
    $label = app(MoveChargesPreview::class)->unitLabels($setup['reservation'])[$setup['units'][0]->id];

    $preview = mcPreview($setup, mcBody($setup['master'], [$room]))->assertOk()->json();

    expect($preview['notes'])->toBe(['2 scheduled nights of Room '.$label.' will still be charged to '.app(MoveChargesPreview::class)->folioLabel($setup['folios'][0]).'.']);
});

test('a same-selector Manual rule is replaced and a BillingMode rule is untouched', function () {
    $setup = mcSetup();
    $agent = Folio::factory()->thirdParty()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $old = FolioRoutingRule::factory()->create(['reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => $setup['units'][0]->id, 'category' => TransactionCategory::Room, 'transaction_code_id' => null, 'target_folio_id' => $agent->id, 'source' => RoutingRuleSource::Manual]);
    $billing = FolioRoutingRule::factory()->create(['reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => null, 'category' => TransactionCategory::Room, 'transaction_code_id' => null, 'target_folio_id' => $agent->id, 'source' => RoutingRuleSource::BillingMode]);

    mcStore($setup, mcBody($setup['master'], [], [$setup['units'][0]->id => 'room']))->assertSessionHasNoErrors();

    $rules = FolioRoutingRule::query()->orderBy('id')->get();

    expect(FolioRoutingRule::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and($rules)->toHaveCount(2)
        ->and($rules->firstWhere('source', RoutingRuleSource::Manual)->target_folio_id)->toBe($setup['master']->id)
        ->and($billing->fresh()->target_folio_id)->toBe($agent->id)
        ->and(Activity::query()->where('event', 'folio.charges_moved')->sole()->properties['rules_replaced'])->toHaveCount(1);

    mcStore($setup, mcBody($setup['master'], [], [$setup['units'][0]->id => 'room']))->assertSessionHasNoErrors();

    expect(FolioRoutingRule::query()->count())->toBe(2);
});

test('the posted move and the rules roll back together', function () {
    $setup = mcSetup();
    $charge = mcPost($setup, 0, $setup['folios'][0]);
    $count = FolioLine::query()->count();
    $logged = Activity::query()->count();
    $this->instance(CreateRoutingRule::class, Mockery::mock(CreateRoutingRule::class, fn ($mock) => $mock->shouldReceive('__invoke')->andReturn(RoutingRuleResult::refused(RoutingRuleResult::REFUSAL_TARGET_FOLIO_NOT_OPEN))));

    $result = app(MoveCharges::class)($setup['user'], $setup['master'], [$charge], [['unit' => $setup['units'][0], 'scope' => 'room']]);

    expect($result->refusal)->toBe(RoutingRuleResult::REFUSAL_TARGET_FOLIO_NOT_OPEN)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(FolioRoutingRule::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($logged);
});

test('a checked-out unit folio left at 0 closes after the move', function () {
    $setup = mcSetup();
    $charge = mcPost($setup, 0, $setup['folios'][0]);
    $setup['units'][0]->forceFill(['status' => 'checked_out', 'checked_out_at' => now()])->save();

    mcStore($setup, mcBody($setup['master'], [$charge]))->assertSessionHasNoErrors();

    expect($setup['folios'][0]->refresh()->status)->toBe(FolioStatus::Closed)
        ->and($setup['master']->refresh()->status)->toBe(FolioStatus::Open);
});

test('200 charges pass and 201 are refused', function () {
    $setup = mcSetup();
    $lines = FolioLine::factory()->count(201)->create(['folio_id' => $setup['folios'][0]->id, 'transaction_code_id' => $setup['room']->id, 'amount' => '1.00', 'unit_amount' => '1.00', 'posting_date' => '2026-10-10'])->all();

    $over = mcPreview($setup, mcBody($setup['master'], $lines))->assertOk()->json();

    expect($over['refusal'])->toBe('You can move up to 200 charges at once. Move them in two parts.');

    mcStore($setup, mcBody($setup['master'], $lines))->assertSessionHasErrors(['form' => 'You can move up to 200 charges at once. Move them in two parts.']);

    expect(mcBalance($setup['master']))->toBe('0.00');

    $result = app(MoveCharges::class)($setup['user'], $setup['master'], array_slice($lines, 0, 200), []);

    expect($result->isRefused())->toBeFalse()
        ->and($result->chargeCount)->toBe(200)
        ->and(mcBalance($setup['master']))->toBe('200.00')
        ->and(mcBalance($setup['folios'][0]))->toBe('1.00');
});

test('the Action refuses an empty move, a unit with nothing scheduled and another reservation, and writes nothing', function (Closure $arrange, string $refusal) {
    $setup = mcSetup();
    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $charge = mcPost($setup, 0, $setup['folios'][0]);
    [$lines, $schedules, $target] = $arrange($setup, $charge, $other);
    $count = FolioLine::query()->count();
    $logged = Activity::query()->count();

    $result = app(MoveCharges::class)($setup['user'], $target ?? $setup['master'], $lines, $schedules);

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(FolioRoutingRule::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($logged);
})->with([
    'nothing to move' => [fn () => [[], [], null], MoveChargesResult::REFUSAL_NOTHING_TO_MOVE],
    'a cancelled unit' => [fn (array $setup, FolioLine $charge, Reservation $other) => [[$charge], [['unit' => ReservationUnit::factory()->cancelled()->withNights()->create(['reservation_id' => $setup['reservation']->id, 'arrival_date' => '2026-10-11', 'departure_date' => '2026-10-13']), 'scope' => 'room']], null], MoveChargesResult::REFUSAL_NOTHING_SCHEDULED],
    'a unit with no night after the business date' => [fn (array $setup, FolioLine $charge) => [[$charge], [['unit' => ReservationUnit::factory()->checkedIn()->withNights()->create(['reservation_id' => $setup['reservation']->id, 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-10']), 'scope' => 'room']], null], MoveChargesResult::REFUSAL_NOTHING_SCHEDULED],
    'a unit of another reservation' => [fn (array $setup, FolioLine $charge, Reservation $other) => [[], [['unit' => ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $other->id]), 'scope' => 'room']], null], PostingResult::REFUSAL_DIFFERENT_RESERVATION],
    'a line of another reservation' => [fn (array $setup, FolioLine $charge, Reservation $other) => [[FolioLine::factory()->create(['folio_id' => Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $other->id])->id])], [], null], PostingResult::REFUSAL_DIFFERENT_RESERVATION],
    'a closed target' => [fn (array $setup, FolioLine $charge) => [[$charge], [], Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id])], PostingResult::REFUSAL_FOLIO_NOT_OPEN],
    'a line already on the target' => [fn (array $setup, FolioLine $charge) => [[$charge], [], $setup['folios'][0]], PostingResult::REFUSAL_TRANSFER_INVALID],
]);

test('the routes need folio.transfer and keep to the reservation', function () {
    $setup = mcSetup();
    $charge = mcPost($setup, 0, $setup['folios'][0]);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $body = mcBody($setup['master'], [$charge]);

    mcPreview($setup, $body, $frontDesk)->assertForbidden();
    mcStore($setup, $body, $frontDesk)->assertForbidden();

    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $foreignFolio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $other->id]);
    $foreignLine = FolioLine::factory()->create(['folio_id' => $foreignFolio->id, 'transaction_code_id' => $setup['room']->id]);
    $foreignUnit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $other->id]);

    foreach (['preview', 'store'] as $route) {
        $call = fn (array $payload) => $route === 'preview' ? mcPreview($setup, $payload) : mcStore($setup, $payload);

        $call(mcBody($foreignFolio, [$charge]))->assertNotFound();
        $call(mcBody($setup['master'], [$foreignLine]))->assertNotFound();
        $call(mcBody($setup['master'], [], [$foreignUnit->id => 'room']))->assertNotFound();
    }

    expect(FolioLine::query()->where('folio_id', $foreignFolio->id)->count())->toBe(1)
        ->and(FolioLine::query()->whereNotNull('transfer_group_id')->count())->toBe(0);
});

test('the preview refuses a target that is not open and writes nothing', function () {
    $setup = mcSetup();
    $closed = Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    mcPreview($setup, mcBody($closed))->assertUnprocessable();
    mcStore($setup, mcBody($closed))->assertSessionHasErrors('form');

    expect(FolioRoutingRule::query()->count())->toBe(0);
});

test('the router routes with proposed rules and without them is unchanged', function () {
    $setup = mcSetup();
    $unit = $setup['units'][0];
    $router = app(FolioRouter::class);
    $own = $router->resolverFor($setup['reservation']);
    $proposed = new FolioRoutingRule(['reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => $unit->id, 'category' => TransactionCategory::Room, 'transaction_code_id' => null, 'target_folio_id' => $setup['master']->id, 'source' => RoutingRuleSource::Manual]);
    $with = $router->resolverFor($setup['reservation'], [$proposed]);

    expect($own($unit, $setup['room']))->toBe($setup['folios'][0]->id)
        ->and($with($unit, $setup['room']))->toBe($setup['master']->id)
        ->and($with($setup['units'][1], $setup['room']))->toBe($setup['folios'][1]->id)
        ->and($router->pickerFor($setup['reservation'], [$proposed])($unit, $setup['room']))->toBe($proposed)
        ->and(FolioRoutingRule::query()->count())->toBe(0);
});

function mcPage(array $setup, array $query = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $setup['user'])->get(route('reservations.folio.move', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, ...$query]));
}

test('the page starts on the Master folio, or on the first other Open folio for a Master charge, and ticks the line', function () {
    $setup = mcSetup();
    $onUnit = mcPost($setup, 0, $setup['folios'][0]);
    $onMaster = mcPost($setup, 1, $setup['master']);

    mcPage($setup)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reservations/move-charges')
        ->where('move.targetId', $setup['master']->id)
        ->where('move.lineIds', [])
        ->where('move.maxCharges', 200)
        ->has('move.folios', 3)
        ->where('move.preview.targetId', $setup['master']->id)
        ->where('move.preview.chargeCount', 0));

    mcPage($setup, ['line' => $onUnit->id])->assertInertia(fn (Assert $page) => $page
        ->where('move.targetId', $setup['master']->id)
        ->where('move.lineIds', [$onUnit->id])
        ->where('move.preview.chargeCount', 1)
        ->where('move.preview.amount', '100.00'));

    mcPage($setup, ['line' => $onMaster->id])->assertInertia(fn (Assert $page) => $page
        ->where('move.targetId', $setup['folios'][0]->id)
        ->where('move.lineIds', [$onMaster->id]));

    mcPage($setup, ['to' => $setup['folios'][1]->id, 'line' => $onUnit->id])->assertInertia(fn (Assert $page) => $page
        ->where('move.targetId', $setup['folios'][1]->id)
        ->where('move.lineIds', [$onUnit->id])
        ->where('move.cancelUrl', route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folios'][1]->id])));

    mcPage($setup, ['to' => $setup['folios'][0]->id, 'line' => $onUnit->id])->assertInertia(fn (Assert $page) => $page
        ->where('move.lineIds', []));
});

test('the page needs folio.transfer, keeps to the reservation and sends a reservation with no open folio back', function () {
    $setup = mcSetup();
    $charge = mcPost($setup, 0, $setup['folios'][0]);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $foreignFolio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $other->id]);
    $foreignLine = FolioLine::factory()->create(['folio_id' => $foreignFolio->id, 'transaction_code_id' => $setup['room']->id]);

    mcPage($setup, [], $frontDesk)->assertForbidden();
    mcPage($setup, ['to' => $foreignFolio->id])->assertNotFound();
    mcPage($setup, ['line' => $foreignLine->id])->assertNotFound();

    Folio::query()->where('reservation_id', $setup['reservation']->id)->update(['status' => FolioStatus::Voided]);

    mcPage($setup, ['line' => $charge->id])
        ->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'There is no open folio to move charges to.']);
});

test('a refusal on the target field shows on the page form', function () {
    $setup = mcSetup();
    $charge = mcPost($setup, 0, $setup['folios'][0]);

    mcStore($setup, mcBody($setup['folios'][0], [$charge]))->assertSessionHasErrors('target_folio_id');
});
