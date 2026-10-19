<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReverseLine;
use App\Actions\NightAudit\PostRoomCharges;
use App\Data\Folio\NewChargeData;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner of a live property with an open business date and the room revenue code.
 *
 * @return array{user: User, property: Property, reservation: Reservation, room: TransactionCode}
 */
function ftSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    return [
        'user' => $user,
        'property' => $property,
        'reservation' => Reservation::factory()->create(['property_id' => $property->id]),
        'room' => TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]),
    ];
}

/** A unit with `$nights` nights from 2026-10-12, each priced at `$amount`. */
function ftUnit(Reservation $reservation, int $nights = 2, string $amount = '100.00', bool $checkedIn = false): ReservationUnit
{
    $factory = ReservationUnit::factory()->for($reservation)->withNights();
    $unit = ($checkedIn ? $factory->checkedIn() : $factory)->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => CarbonImmutable::parse('2026-10-12')->addDays($nights)->toDateString(),
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => $amount, 'amount' => $amount]);

    return $unit;
}

function ftTax(Property $property, TransactionCode $code, string $rate = '8.00'): Tax
{
    $tax = Tax::factory()->for($property)->create(['rate' => $rate, 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false, 'name' => 'Service Tax '.$rate.'%']);
    $tax->applicableTransactionCodes()->attach($code);

    return $tax;
}

function ftGet(array $setup, array $query = []): TestResponse
{
    return test()->actingAs($setup['user'])
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, ...$query]))
        ->assertOk();
}

function ftCharge(array $setup, ReservationUnit $unit, string $amount = '12.00', ?TransactionCode $code = null): FolioLine
{
    return app(PostCharge::class)($setup['user'], $unit, NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => ($code ?? $setup['room'])->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]))->line();
}

test('a unit with no folio gets a not-started entry with its scheduled nights', function () {
    $setup = ftSetup();
    ftUnit($setup['reservation'], 2, '100.00');

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->component('reservations/show')
        ->where('tab', 'folio')
        ->has('folios', 1)
        ->where('folios.0.kind', 'not_started')
        ->where('folios.0.balance', null)
        ->where('folio.key', 'u'.$setup['reservation']->units()->value('id'))
        ->where('folio.balance', null)
        ->where('folio.projectedTotal', '200.00')
        ->where('folio.lineCount', 0)
        ->has('folio.rows', 2)
        ->where('folio.rows.0.state', 'scheduled')
        ->where('folio.rows.0.tags.0.value', 'scheduled')->has('folio.rows.0.tags', 1));
});

test('a scheduled night shows its stay discount, booking discount and tax in order', function () {
    $setup = ftSetup();
    ftTax($setup['property'], $setup['room'], '8.00');
    $discount = Discount::factory()->for($setup['property'])->create(['name' => 'Staff rate', 'type' => DiscountType::Percent, 'value' => '10.00', 'scope' => DiscountScope::RoomOnly]);
    $unit = ftUnit($setup['reservation'], 1, '252.00');
    $unit->forceFill(['discount_id' => $discount->id])->save();
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'discount_amount' => '48.00', 'amount' => '252.00']);

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->where('folio.rows', fn ($rows) => collect($rows)->map(fn ($row) => [$row['description'], $row['level'], $row['amount']])->all() === [
            ['Room · '.$unit->ratePlan->name, 0, '300.00'],
            ['Stay discount', 1, '-48.00'],
            ['Staff rate', 1, '-25.20'],
            ['Service Tax 8.00%', 1, '18.14'],
        ])
        ->where('folio.projectedTotal', '244.94'));
});

test('a scheduled night splits its inclusions off the room, each with its own tax', function () {
    $setup = ftSetup();
    ftTax($setup['property'], $setup['room'], '8.00');
    $unit = ftUnit($setup['reservation'], 1, '300.00');
    $unit->forceFill(['adults' => 2])->save();
    $inclusion = RatePlanInclusion::factory()->create(['rate_plan_id' => $unit->rate_plan_id, 'charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '25.00']);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'amount' => '300.00']);

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->where('folio.rows', fn ($rows) => collect($rows)->map(fn ($row) => [$row['description'], $row['level'], $row['amount']])->all() === [
            ['Room · '.$unit->ratePlan->name, 0, '250.00'],
            ['Service Tax 8.00%', 1, '20.00'],
            [$inclusion->chargeItem->name, 0, '50.00'],
        ]));
});

test('an overridden night is one row without a discount child', function () {
    $setup = ftSetup();
    $unit = ftUnit($setup['reservation'], 1, '250.00');
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['override_amount' => '250.00', 'discount_amount' => '10.00', 'base_amount' => '300.00', 'amount' => '250.00']);

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->has('folio.rows', 1)
        ->where('folio.rows.0.amount', '250.00')
        ->where('folio.rows.0.tags.0.value', 'scheduled')->where('folio.rows.0.tags.1.value', 'overridden')->has('folio.rows.0.tags', 2));
});

test('a posted night key replaces its scheduled row so a night never shows twice', function () {
    $setup = ftSetup();
    $unit = ftUnit($setup['reservation'], 2, '100.00');
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    $night = $unit->nights()->orderBy('date')->first();
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $setup['room']->id, 'amount' => '100.00', 'unit_amount' => '100.00', 'posting_date' => '2026-10-12', 'idempotency_key' => 'night:'.$night->id]);

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->where('folio.key', (string) $folio->id)
        ->where('folio.balance', '100.00')
        ->where('folio.projectedTotal', '200.00')
        ->where('folio.lineCount', 1)
        ->has('folio.rows', 2)
        ->where('folio.rows.0.state', 'posted')
        ->where('folio.rows.1.state', 'scheduled')
        ->where('folio.rows.1.date', '2026-10-13'));
});

test('a scheduled add-on shows until it is posted', function () {
    $setup = ftSetup();
    $unit = ftUnit($setup['reservation'], 1, '100.00');
    $scheduled = UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'quantity' => 2, 'unit_amount' => '20.00', 'service_date' => '2026-10-12']);
    UnitAddOn::factory()->posted()->create(['reservation_unit_id' => $unit->id]);

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->where('folio.rows', fn ($rows) => collect($rows)->pluck('key')->all() === ['night:'.$unit->nights()->value('id'), 'addon:'.$scheduled->id])
        ->where('folio.rows.1.description', $scheduled->chargeItem->name.' ×2')
        ->where('folio.rows.1.amount', '40.00'));
});

test('routing puts scheduled room rows on the master folio and none on the unit folio', function () {
    $setup = ftSetup();
    $one = ftUnit($setup['reservation'], 1, '100.00');
    $two = ftUnit($setup['reservation'], 1, '100.00');
    $unitFolio = app(CreateFolio::class)($setup['reservation'], $one);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->toFolio($master)->create(['reservation_id' => $setup['reservation']->id]);

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->where('folios.0.kind', 'master')
        ->where('folio.key', (string) $master->id)
        ->has('folio.rows', 2));

    ftGet($setup, ['folio' => (string) $unitFolio->id])->assertInertia(fn (Assert $page) => $page
        ->where('folio.key', (string) $unitFolio->id)
        ->has('folio.rows', 0)
        ->where('folio.projectedTotal', '0.00'));

    expect($two->id)->not->toBe($one->id);
});

test('posted lines come in ledger order with children and reversal tags', function () {
    $setup = ftSetup();
    ftTax($setup['property'], $setup['room'], '8.00');
    $unit = ftUnit($setup['reservation'], 1, '100.00', checkedIn: true);
    $charge = ftCharge($setup, $unit, '100.00');
    app(ReverseLine::class)($setup['user'], $charge);

    ftGet($setup, ['folio' => (string) $charge->folio_id])->assertInertia(fn (Assert $page) => $page
        ->where('folio.rows', fn ($rows) => collect($rows)->take(4)->map(fn ($row) => [$row['level'], collect($row['tags'])->pluck('value')->all()])->all() === [
            [0, ['reversed']],
            [1, ['reversed']],
            [0, ['reversal']],
            [1, ['reversal']],
        ])
        ->where('folio.balance', '0.00'));
});

test('an unknown folio falls back to the master folio and the summary shows the real balance', function () {
    $setup = ftSetup();
    $unit = ftUnit($setup['reservation'], 1, '100.00', checkedIn: true);
    $line = ftCharge($setup, $unit, '50.00');

    ftGet($setup, ['folio' => '999999'])->assertInertia(fn (Assert $page) => $page
        ->where('folio.key', (string) $line->folio_id)
        ->where('reservation.balance', '50.00'));
});

test('the folio tab is limited to reservations.view and the property', function () {
    $setup = ftSetup();
    ftUnit($setup['reservation']);
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);
    $this->actingAs($housekeeping)->get($url)->assertForbidden();

    $other = Reservation::factory()->create();
    $this->actingAs($setup['user'])
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $other->id]))
        ->assertNotFound();
});

test('posted rows on the master folio name their unit, and a child takes its parent unit', function () {
    $setup = ftSetup();
    ftTax($setup['property'], $setup['room']);
    $one = ftUnit($setup['reservation'], 1, '100.00', true);
    $two = ftUnit($setup['reservation'], 1, '100.00', true);
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    FolioRoutingRule::factory()->toFolio($master)->create(['reservation_id' => $setup['reservation']->id]);
    UnitNight::query()->whereIn('reservation_unit_id', [$one->id, $two->id])->update(['date' => '2026-10-10']);
    app(PostRoomCharges::class)(null, $one, CarbonImmutable::parse('2026-10-10'));
    $manual = ftCharge($setup, $two, '20.00');
    $reversed = app(ReverseLine::class)($setup['user'], $manual)->line();

    ftGet($setup)->assertInertia(fn (Assert $page) => $page
        ->where('folio.rows', function ($rows) use ($manual, $reversed) {
            $posted = collect($rows)->where('state', 'posted')->values();
            $byLine = $posted->keyBy('key');
            $roomUnit = $posted[0]['unit'];
            $manualUnit = $byLine['line:'.$manual->id]['unit'];

            return $roomUnit !== null && $manualUnit !== null && $roomUnit !== $manualUnit
                && $posted[1]['kind'] === 'tax' && $posted[1]['unit'] === $roomUnit
                && $byLine['line:'.$reversed->id]['unit'] === $manualUnit;
        }));
});

test('a payment row has no unit', function () {
    $setup = ftSetup();
    $unit = ftUnit($setup['reservation'], 1, '100.00', true);
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => TransactionKind::Payment, 'amount' => '-10.00', 'unit_amount' => '-10.00']);

    ftGet($setup)->assertInertia(fn (Assert $page) => $page->where('folio.rows.0.unit', null));
});

test('the folio tab runs the same queries for few and many units', function () {
    $setup = ftSetup();
    ftTax($setup['property'], $setup['room']);

    $queries = function (int $units) use ($setup): int {
        $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
        $charge = TransactionCode::factory()->create(['property_id' => $setup['property']->id]);
        $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $reservation->id]);
        FolioRoutingRule::factory()->toFolio($master)->create(['reservation_id' => $reservation->id]);

        foreach (range(1, $units) as $ignored) {
            $unit = ftUnit($reservation, 3, '100.00', true);
            UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'service_date' => '2026-10-12']);
            app(PostCharge::class)($setup['user'], $unit, NewChargeData::from(['chargeItemId' => null, 'transactionCodeId' => $setup['room']->id, 'description' => null, 'quantity' => 1, 'unitAmount' => '12.00', 'idempotencyKey' => 'manual:'.fake()->uuid()]));
        }

        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($setup['user'])->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $reservation->id]))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $count;
    };

    $queries(1);
    $few = $queries(2);
    $many = $queries(8);

    // The header's `money` prop (BookingSummary) runs on every tab and adds about 25 queries. The manual row menu adds 2, and the go-live date read for the Scheduled rows adds 1 for each of the two callers.
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(69);
});
