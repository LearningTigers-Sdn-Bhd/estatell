<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\CreateRoutingRule;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\RoutingRuleResult;
use App\Actions\Folio\SyncRoutingRules;
use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\UpdateReservationGroup;
use App\Data\Folio\NewChargeData;
use App\Data\Reservations\NewReservationData;
use App\Data\Reservations\NewUnitData;
use App\Enums\BillingMode;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\ReservationSource;
use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\RoutingRuleCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date and a reservation of 2 checked-in units (MasterPays by default), an actor,
 * a Room code and a Diving code (category Other).
 *
 * @param  array<string, mixed>  $reservationAttributes
 * @return array{property: Property, actor: User, reservation: Reservation, unit1: ReservationUnit, unit2: ReservationUnit, room: TransactionCode, diving: TransactionCode}
 */
function routingSetup(array $reservationAttributes = [], int $units = 2): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, ...$reservationAttributes]);
    $created = ReservationUnit::factory()->checkedIn()->count($units)->create(['reservation_id' => $reservation->id]);

    return [
        'property' => $property,
        'actor' => User::factory()->create(['property_id' => $property->id]),
        'reservation' => $reservation,
        'unit1' => $created[0],
        'unit2' => $created[1] ?? $created[0],
        'room' => TransactionCode::factory()->create(['property_id' => $property->id, 'category' => TransactionCategory::Room]),
        'diving' => TransactionCode::factory()->create(['property_id' => $property->id, 'category' => TransactionCategory::Other]),
    ];
}

function routingPost(array $setup, ReservationUnit $unit, TransactionCode $code, string $amount = '100.00'): PostingResult
{
    return app(PostCharge::class)($setup['actor'], $unit, NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $code->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]));
}

function routingRule(Reservation $reservation, Folio $target, ?ReservationUnit $unit = null, ?TransactionCategory $category = null, ?TransactionCode $code = null, RoutingRuleSource $source = RoutingRuleSource::Manual): FolioRoutingRule
{
    return FolioRoutingRule::factory()->create([
        'reservation_id' => $reservation->id,
        'reservation_unit_id' => $unit?->id,
        'category' => $category,
        'transaction_code_id' => $code?->id,
        'target_folio_id' => $target->id,
        'source' => $source,
    ]);
}

function routingFolio(array $setup, string $state = 'master'): Folio
{
    return Folio::factory()->{$state}()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
}

/**
 * A property with rates, a reservation of 1 unit and an actor, ready for `AddUnit`.
 *
 * @return array{property: Property, plan: RatePlan, roomType: RoomType, reservation: Reservation, user: User, arrival: CarbonImmutable}
 */
function routingBookingSetup(array $reservationAttributes = []): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count(3)->for($property)->create(['room_type_id' => $roomType->id]);
    $plan->roomTypes()->attach($roomType);
    $arrival = CarbonImmutable::now()->addDays(30)->startOfDay();

    for ($night = 0; $night < 3; $night++) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $arrival->addDays($night)->toDateString(), 'amount' => '100.00']);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id, ...$reservationAttributes]);
    ReservationUnit::factory()->create(['reservation_id' => $reservation->id, 'room_type_id' => $roomType->id, 'rate_plan_id' => $plan->id]);

    return ['property' => $property, 'plan' => $plan, 'roomType' => $roomType, 'reservation' => $reservation, 'user' => User::factory()->for($property)->create(), 'arrival' => $arrival];
}

function routingUnitData(array $setup): NewUnitData
{
    return NewUnitData::from([
        'roomTypeId' => $setup['roomType']->id,
        'ratePlanId' => $setup['plan']->id,
        'arrivalDate' => $setup['arrival']->toDateString(),
        'departureDate' => $setup['arrival']->addDays(3)->toDateString(),
        'adults' => 2,
    ]);
}

test('the router picks the most specific rule: code, then category, then all, and a unit rule beats an all units rule', function () {
    $setup = routingSetup();
    $router = app(FolioRouter::class);
    $all = routingFolio($setup);
    $category = routingFolio($setup, 'thirdParty');
    $code = routingFolio($setup, 'thirdParty');
    $unitFolio = routingFolio($setup, 'thirdParty');

    routingRule($setup['reservation'], $all);
    expect($router->targetFor($setup['unit1'], $setup['diving'])->is($all))->toBeTrue();

    routingRule($setup['reservation'], $category, category: TransactionCategory::Other);
    expect($router->targetFor($setup['unit1'], $setup['diving'])->is($category))->toBeTrue()
        ->and($router->targetFor($setup['unit1'], $setup['room'])->is($all))->toBeTrue();

    routingRule($setup['reservation'], $code, code: $setup['diving']);
    expect($router->targetFor($setup['unit1'], $setup['diving'])->is($code))->toBeTrue();

    routingRule($setup['reservation'], $unitFolio, unit: $setup['unit1'], category: TransactionCategory::Other);
    expect($router->targetFor($setup['unit1'], $setup['diving'])->is($code))->toBeTrue()
        ->and($router->targetFor($setup['unit2'], $setup['diving'])->is($code))->toBeTrue();

    $unitCode = routingFolio($setup, 'thirdParty');
    routingRule($setup['reservation'], $unitCode, unit: $setup['unit1'], code: $setup['diving']);
    expect($router->targetFor($setup['unit1'], $setup['diving'])->is($unitCode))->toBeTrue()
        ->and($router->targetFor($setup['unit2'], $setup['diving'])->is($code))->toBeTrue();
});

test('a unit rule beats an all units rule at the same level', function () {
    $setup = routingSetup();
    $master = routingFolio($setup);
    $agent = routingFolio($setup, 'thirdParty');
    routingRule($setup['reservation'], $master, category: TransactionCategory::Room);
    routingRule($setup['reservation'], $agent, unit: $setup['unit2'], category: TransactionCategory::Room);

    $router = app(FolioRouter::class);

    expect($router->targetFor($setup['unit1'], $setup['room'])->is($master))->toBeTrue()
        ->and($router->targetFor($setup['unit2'], $setup['room'])->is($agent))->toBeTrue();
});

test('with no matching rule the router gives the unit folio, or null before it exists', function () {
    $setup = routingSetup();
    $master = routingFolio($setup);
    routingRule($setup['reservation'], $master, category: TransactionCategory::Room);
    routingRule(Reservation::factory()->create(['property_id' => $setup['property']->id]), Folio::factory()->create(['property_id' => $setup['property']->id]));
    $router = app(FolioRouter::class);

    expect($router->targetFor($setup['unit1'], $setup['diving']))->toBeNull();

    $own = app(CreateFolio::class)($setup['reservation'], $setup['unit1']);

    expect($router->targetFor($setup['unit1'], $setup['diving'])->is($own))->toBeTrue();
});

test('the router follows one rule and does not chain', function () {
    $setup = routingSetup();
    $unit2Folio = app(CreateFolio::class)($setup['reservation'], $setup['unit2']);
    $master = routingFolio($setup);
    routingRule($setup['reservation'], $unit2Folio, unit: $setup['unit1']);
    routingRule($setup['reservation'], $master, unit: $setup['unit2']);

    $result = routingPost($setup, $setup['unit1'], $setup['room']);

    expect($result->line()->folio_id)->toBe($unit2Folio->id)
        ->and(app(FolioRouter::class)->targetFor($setup['unit2'], $setup['room'])->is($master))->toBeTrue();
});

test('on a MasterPays group the room charge goes to the master folio, an extra stays on the unit folio and tax follows its charge', function () {
    $setup = routingSetup();
    $tax = Tax::factory()->for($setup['property'])->create(['rate' => '10.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach([$setup['room']->id, $setup['diving']->id]);
    $master = app(SyncRoutingRules::class)($setup['reservation'])->masterFolio;

    $room = routingPost($setup, $setup['unit1'], $setup['room']);
    $extra = routingPost($setup, $setup['unit1'], $setup['diving'], '50.00');

    expect($room->lines)->toHaveCount(2)
        ->and(collect($room->lines)->pluck('folio_id')->unique()->all())->toBe([$master->id])
        ->and($extra->lines)->toHaveCount(2)
        ->and(collect($extra->lines)->pluck('folio_id')->unique()->all())->toHaveCount(1)
        ->and($extra->line()->folio->type)->toBe(FolioType::Unit)
        ->and($extra->line()->folio->reservation_unit_id)->toBe($setup['unit1']->id);
});

test('MasterPaysAll sends every charge to the master folio and leaves the unit folios empty', function () {
    $setup = routingSetup(['billing_mode' => BillingMode::MasterPaysAll]);
    $master = app(SyncRoutingRules::class)($setup['reservation'])->masterFolio;

    $room = routingPost($setup, $setup['unit1'], $setup['room']);
    $extra = routingPost($setup, $setup['unit2'], $setup['diving']);

    expect($room->line()->folio_id)->toBe($master->id)
        ->and($extra->line()->folio_id)->toBe($master->id)
        ->and(Folio::query()->where('type', FolioType::Unit)->count())->toBe(0);
});

test('a code rule sends diving charges to a third party folio and everything else stays', function () {
    $setup = routingSetup();
    $agentGuest = Guest::factory()->create(['property_id' => $setup['property']->id]);
    $agent = app(CreateFolio::class)->thirdParty($setup['reservation'], $agentGuest);
    routingRule($setup['reservation'], $agent, code: $setup['diving']);

    $diving = routingPost($setup, $setup['unit1'], $setup['diving']);
    $other = routingPost($setup, $setup['unit1'], $setup['room']);

    expect($diving->line()->folio_id)->toBe($agent->id)
        ->and($agent->type)->toBe(FolioType::ThirdParty)
        ->and($agent->reservation_unit_id)->toBeNull()
        ->and($agent->payer_guest_id)->toBe($agentGuest->id)
        ->and($other->line()->folio->type)->toBe(FolioType::Unit);
});

test('a third party folio takes a guest or a company as its only payer', function () {
    $setup = routingSetup();
    $create = app(CreateFolio::class);

    $company = $create->thirdParty($setup['reservation'], null, 88);

    expect($company->payer_company_id)->toBe(88)
        ->and($company->payer_guest_id)->toBeNull()
        ->and(fn () => $create->thirdParty($setup['reservation'], null, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $create->thirdParty($setup['reservation'], Guest::factory()->create(['property_id' => $setup['property']->id]), 88))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $create->thirdParty($setup['reservation'], Guest::factory()->create(), null))->toThrow(InvalidArgumentException::class);
});

test('a closed target folio refuses the posting and writes nothing', function () {
    $setup = routingSetup();
    $master = routingFolio($setup, 'closed');
    routingRule($setup['reservation'], $master, category: TransactionCategory::Room);

    $result = routingPost($setup, $setup['unit1'], $setup['room']);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Folio::query()->where('type', FolioType::Unit)->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('a reversal stays on the folio of its line even after the rule is gone', function () {
    $setup = routingSetup();
    $master = routingFolio($setup);
    $rule = routingRule($setup['reservation'], $master, category: TransactionCategory::Room);
    $charge = routingPost($setup, $setup['unit1'], $setup['room'])->line();

    $rule->delete();
    $reversal = app(ReverseLine::class)($setup['actor'], $charge)->line();

    expect($charge->folio_id)->toBe($master->id)
        ->and($reversal->folio_id)->toBe($master->id);
});

test('a rule change affects only later postings', function () {
    $setup = routingSetup();
    $master = routingFolio($setup);
    $before = routingPost($setup, $setup['unit1'], $setup['room'])->line();
    routingRule($setup['reservation'], $master, category: TransactionCategory::Room);
    $after = routingPost($setup, $setup['unit1'], $setup['room'])->line();

    expect($before->refresh()->folio->type)->toBe(FolioType::Unit)
        ->and($after->folio_id)->toBe($master->id);
});

test('SyncRoutingRules makes the master folio and the Room rule once and a second run changes nothing', function () {
    $setup = routingSetup();
    $sync = app(SyncRoutingRules::class);

    $first = $sync($setup['reservation']);
    $second = $sync($setup['reservation']);
    $rule = FolioRoutingRule::query()->sole();

    expect($first->masterFolioCreated)->toBeTrue()
        ->and($first->rulesCreated)->toBe(1)
        ->and($second->masterFolioCreated)->toBeFalse()
        ->and($second->rulesCreated)->toBe(0)
        ->and($second->rulesRemoved)->toBe(0)
        ->and(Folio::query()->count())->toBe(1)
        ->and($rule->category)->toBe(TransactionCategory::Room)
        ->and($rule->reservation_unit_id)->toBeNull()
        ->and($rule->transaction_code_id)->toBeNull()
        ->and($rule->source)->toBe(RoutingRuleSource::BillingMode)
        ->and($rule->target_folio_id)->toBe($first->masterFolio->id)
        ->and($first->masterFolio->type)->toBe(FolioType::Master)
        ->and($first->masterFolio->payer_company_id)->toBeNull()
        ->and($first->masterFolio->payer_guest_id)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('one unit and no company gets no master folio and no rules', function () {
    $setup = routingSetup(units: 1);

    $result = app(SyncRoutingRules::class)($setup['reservation']);

    expect($result->masterFolio)->toBeNull()
        ->and(Folio::query()->count())->toBe(0)
        ->and(FolioRoutingRule::query()->count())->toBe(0);
});

test('a company with one unit gets a master folio paid by the company and the rules', function () {
    $setup = routingSetup(['company_id' => 55], units: 1);

    $result = app(SyncRoutingRules::class)($setup['reservation']);

    expect($result->masterFolio->payer_company_id)->toBe(55)
        ->and($result->masterFolio->payer_guest_id)->toBeNull()
        ->and(FolioRoutingRule::query()->sole()->category)->toBe(TransactionCategory::Room);
});

test('a company with each unit paying gets a master folio and no rules', function () {
    $setup = routingSetup(['company_id' => 55, 'billing_mode' => BillingMode::EachPays], units: 1);

    $result = app(SyncRoutingRules::class)($setup['reservation']);

    expect($result->masterFolio->payer_company_id)->toBe(55)
        ->and(FolioRoutingRule::query()->count())->toBe(0);
});

test('AddUnit takes a reservation from 1 to 2 units and creates the master folio and the Room rule', function () {
    $setup = routingBookingSetup();

    expect(FolioRoutingRule::query()->count())->toBe(0);

    $result = app(AddUnit::class)($setup['user'], $setup['reservation'], routingUnitData($setup));

    $rule = FolioRoutingRule::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($result->unitsWithPostedRoomLines)->toBe([])
        ->and($rule->reservation_id)->toBe($setup['reservation']->id)
        ->and($rule->category)->toBe(TransactionCategory::Room)
        ->and($rule->targetFolio->type)->toBe(FolioType::Master);
});

test('AddUnit names units whose posted room lines stay on their own folio', function () {
    $setup = routingBookingSetup();
    $first = $setup['reservation']->units()->sole();
    $room = TransactionCode::factory()->create(['property_id' => $setup['property']->id, 'category' => TransactionCategory::Room]);
    $folio = app(CreateFolio::class)($setup['reservation'], $first);
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $room->id]);

    $result = app(AddUnit::class)($setup['user'], $setup['reservation'], routingUnitData($setup));

    expect($result->unitsWithPostedRoomLines)->toBe([$first->id])
        ->and($folio->lines()->count())->toBe(1);
});

test('a reversed room line does not count as posted', function () {
    $setup = routingSetup();
    $charge = routingPost($setup, $setup['unit1'], $setup['room'])->line();
    app(ReverseLine::class)($setup['actor'], $charge);

    expect(app(SyncRoutingRules::class)($setup['reservation'])->unitsWithPostedRoomLines)->toBe([]);
});

test('CreateReservation with 2 units creates the master folio and the rule', function () {
    $setup = routingBookingSetup();
    $setup['reservation']->units()->delete();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id]);
    $line = [
        'roomTypeId' => $setup['roomType']->id,
        'ratePlanId' => $setup['plan']->id,
        'arrivalDate' => $setup['arrival']->toDateString(),
        'departureDate' => $setup['arrival']->addDays(2)->toDateString(),
        'adults' => 1,
    ];

    $result = app(CreateReservation::class)($setup['user'], $setup['property'], NewReservationData::from([
        'bookerGuestId' => $guest->id,
        'source' => ReservationSource::Direct->value,
        'billingMode' => BillingMode::MasterPaysAll->value,
        'units' => [$line, $line],
    ]));

    $rule = FolioRoutingRule::query()->sole();

    expect($result->reservation->routingRules()->count())->toBe(1)
        ->and($rule->category)->toBeNull()
        ->and($rule->targetFolio->reservation_id)->toBe($result->reservation->id)
        ->and(Folio::query()->where('reservation_id', $result->reservation->id)->count())->toBe(1);
});

test('switching to EachPays removes only BillingMode rules and keeps Manual rules and the master folio', function () {
    $setup = routingSetup();
    $master = app(SyncRoutingRules::class)($setup['reservation'])->masterFolio;
    $agent = routingFolio($setup, 'thirdParty');
    $manual = routingRule($setup['reservation'], $agent, code: $setup['diving']);
    $manualAll = routingRule($setup['reservation'], $agent, unit: $setup['unit1'], category: TransactionCategory::Room);

    $result = app(UpdateReservationGroup::class)($setup['actor'], $setup['reservation'], null, BillingMode::EachPays);

    expect($result->reservation->billing_mode)->toBe(BillingMode::EachPays)
        ->and($result->unitsWithPostedRoomLines)->toBe([])
        ->and(FolioRoutingRule::query()->pluck('id')->sort()->values()->all())->toBe(collect([$manual->id, $manualAll->id])->sort()->values()->all())
        ->and(FolioRoutingRule::query()->where('source', RoutingRuleSource::BillingMode)->count())->toBe(0)
        ->and($master->fresh()->status)->toBe(FolioStatus::Open);
});

test('changing MasterPays to MasterPaysAll swaps the rule and names units with posted room lines', function () {
    $setup = routingSetup();
    routingPost($setup, $setup['unit1'], $setup['room']);
    $posted = FolioLine::query()->sole();
    app(SyncRoutingRules::class)($setup['reservation']);
    expect(FolioRoutingRule::query()->sole()->category)->toBe(TransactionCategory::Room);

    $result = app(UpdateReservationGroup::class)($setup['actor'], $setup['reservation'], null, BillingMode::MasterPaysAll);
    $rule = FolioRoutingRule::query()->sole();
    $master = Folio::query()->where('type', FolioType::Master)->sole();

    expect($rule->category)->toBeNull()
        ->and($rule->target_folio_id)->toBe($master->id)
        ->and($result->unitsWithPostedRoomLines)->toBe([$setup['unit1']->id])
        ->and($posted->fresh()->folio->type)->toBe(FolioType::Unit);
});

test('a Manual rule on the same selectors keeps its place and the sync adds no second rule', function () {
    $setup = routingSetup();
    $agent = routingFolio($setup, 'thirdParty');
    $manual = routingRule($setup['reservation'], $agent, category: TransactionCategory::Room);

    $result = app(SyncRoutingRules::class)($setup['reservation']);

    expect($result->rulesCreated)->toBe(0)
        ->and(FolioRoutingRule::query()->sole()->is($manual))->toBeTrue();
});

test('a rule whose target folio belongs to another reservation is refused and writes nothing', function () {
    $setup = routingSetup();
    $other = Folio::factory()->create(['property_id' => $setup['property']->id]);

    $result = app(CreateRoutingRule::class)($setup['reservation'], $other, RoutingRuleSource::Manual, null, TransactionCategory::Room);

    expect($result->isRefused())->toBeTrue()
        ->and($result->refusal)->toBe(RoutingRuleCheck::REFUSAL_TARGET_FOLIO_NOT_IN_RESERVATION)
        ->and(FolioRoutingRule::query()->count())->toBe(0);
});

test('a rule is refused for a unit of another reservation, a code of another property and a repeated selector', function () {
    $setup = routingSetup();
    $master = routingFolio($setup);
    $create = app(CreateRoutingRule::class);
    $otherUnit = ReservationUnit::factory()->create();
    $foreignCode = TransactionCode::factory()->create();

    expect($create($setup['reservation'], $master, RoutingRuleSource::Manual, $otherUnit->id)->refusal)->toBe(RoutingRuleCheck::REFUSAL_UNIT_NOT_IN_RESERVATION)
        ->and($create($setup['reservation'], $master, RoutingRuleSource::Manual, null, null, $foreignCode->id)->refusal)->toBe(RoutingRuleCheck::REFUSAL_TRANSACTION_CODE_NOT_FOUND)
        ->and($create($setup['reservation'], $master, RoutingRuleSource::Manual, $setup['unit1']->id, null, $setup['diving']->id)->isRefused())->toBeFalse()
        ->and($create($setup['reservation'], $master, RoutingRuleSource::Manual, $setup['unit1']->id, null, $setup['diving']->id)->refusal)->toBe(RoutingRuleResult::REFUSAL_RULE_EXISTS)
        ->and(FolioRoutingRule::query()->count())->toBe(1);
});

test('the database rejects two rules with the same selectors even when some are null', function () {
    $setup = routingSetup();
    $master = routingFolio($setup);
    routingRule($setup['reservation'], $master);

    expect(fn () => DB::transaction(fn () => routingRule($setup['reservation'], $master)))->toThrow(QueryException::class);

    routingRule($setup['reservation'], $master, category: TransactionCategory::Room);

    expect(FolioRoutingRule::query()->count())->toBe(2);
});

test('the database rejects a second active master folio, accepts a new one after a void, and allows any number of third party folios', function () {
    $setup = routingSetup();
    $first = routingFolio($setup);

    expect(fn () => DB::transaction(fn () => routingFolio($setup)))->toThrow(QueryException::class);

    $first->forceFill(['status' => FolioStatus::Voided])->save();
    $second = routingFolio($setup);
    routingFolio($setup, 'thirdParty');
    routingFolio($setup, 'thirdParty');
    routingFolio($setup, 'thirdParty');

    expect($second->status)->toBe(FolioStatus::Open)
        ->and(Folio::query()->where('type', FolioType::ThirdParty)->count())->toBe(3)
        ->and(fn () => DB::transaction(fn () => routingFolio($setup)))->toThrow(QueryException::class);
});

test('a closed master still counts as the active master, so the sync makes no second one', function () {
    $setup = routingSetup();
    $closed = routingFolio($setup, 'closed');

    $result = app(SyncRoutingRules::class)($setup['reservation']);

    expect($result->masterFolio->is($closed))->toBeTrue()
        ->and($result->masterFolioCreated)->toBeFalse()
        ->and(Folio::query()->count())->toBe(1);
});
