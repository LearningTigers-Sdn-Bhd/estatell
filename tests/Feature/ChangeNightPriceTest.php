<?php

use App\Actions\Folio\PostingResult;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\NightAudit\PostRoomCharges;
use App\Actions\Reservations\ChangeNightPrice;
use App\Actions\Reservations\NightPriceResult;
use App\Enums\ArInvoiceStatus;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\ScheduledFolioRows;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property trading on 2026-10-12 with an 8% exclusive tax on room revenue, and a checked-in unit of 2 adults
 * for 2 nights from that date at 300.00 a night. The user holds the given default role.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, tax: Tax}
 */
function npSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Discount)->create(['property_id' => $property->id]);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'name' => 'Service Tax']);
    $tax->applicableTransactionCodes()->attach($room);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        'adults' => 2,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'amount' => '300.00']);

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'unit' => $unit, 'tax' => $tax];
}

/**
 * @return list<UnitNight>
 */
function npNights(ReservationUnit $unit): array
{
    return $unit->nights()->orderBy('date')->get()->all();
}

function npChange(array $setup, array $nights, string $price, string $reason = 'Corporate rate agreed'): NightPriceResult
{
    return app(ChangeNightPrice::class)($setup['user'], $setup['unit'], $nights, $price, $reason);
}

function npUrl(array $setup, string $path = 'night-price'): string
{
    return "/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/units/{$setup['unit']->id}/{$path}";
}

test('a scheduled night gets the override, posts nothing, and the audit charges the new price', function () {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);

    $result = npChange($setup, [$first], '220');

    expect($result->isRefused())->toBeFalse()->and($result->changed)->toBe(1)->and($result->corrected)->toBe(0);
    expect($first->refresh()->override_amount)->toBe('220.00')->and($first->amount)->toBe('220.00')
        ->and(FolioLine::query()->count())->toBe(0);
    $activity = Activity::query()->where('event', 'reservation.night_price_changed')->sole();
    expect($activity->properties['reason'])->toBe('Corporate rate agreed')
        ->and($activity->properties['nights'][0])->toMatchArray(['old_amount' => '300.00', 'new_amount' => '220.00', 'charged' => false]);

    $posted = app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));

    expect($posted->lines[0]->idempotency_key)->toBe('night:'.$first->id)->and($posted->lines[0]->amount)->toBe('220.00');
});

test('a charged night reverses every line and posts again under fix keys, and a second change uses the next key', function () {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);
    $inclusion = RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '25.00']);
    $first->forceFill(['discount_amount' => '48.00', 'amount' => '252.00'])->save();
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));
    $original = FolioLine::query()->orderBy('id')->get();
    BusinessDate::query()->update(['date' => '2026-10-13']);

    $result = npChange($setup, [$first], '220.00');

    expect($result->corrected)->toBe(1);
    expect($original->every(fn (FolioLine $line): bool => $line->reversal()->exists()))->toBeTrue();
    $fix = 'night:'.$first->id.':fix:1';
    $new = FolioLine::query()->where('idempotency_key', 'like', $fix.'%')->orderBy('id')->get();
    expect($new->map(fn (FolioLine $line) => [$line->idempotency_key, $line->amount])->all())->toBe([
        [$fix, '170.00'],
        [$fix.':tax:'.$setup['tax']->id, '13.60'],
        [$fix.':incl:'.$inclusion->id, '50.00'],
    ]);
    expect($new[0]->posting_date->toDateString())->toBe('2026-10-13')
        ->and($new[0]->description)->toEndWith('· night of 12 Oct, corrected')
        ->and($new[0]->source_id)->toBe($first->id);
    expect(FolioLine::query()->sum('amount'))->toEqual('233.60');

    npChange($setup, [$first->refresh()], '200.00');

    expect(FolioLine::query()->where('idempotency_key', 'night:'.$first->id.':fix:2')->value('amount'))->toBe('150.00')
        ->and($new->every(fn (FolioLine $line): bool => $line->reversal()->exists()))->toBeTrue()
        ->and(FolioLine::query()->sum('amount'))->toEqual('212.00');
});

test('the audit never charges a corrected night again and the folio shows no scheduled row for it', function () {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));
    npChange($setup, [$first], '220.00');
    $count = FolioLine::query()->count();

    $rerun = app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));

    expect($rerun->replayed)->toBeTrue()->and(FolioLine::query()->count())->toBe($count);
    $blocks = app(ScheduledFolioRows::class)->forReservation($setup['property'], $setup['reservation'], []);
    expect(collect($blocks)->pluck('date')->all())->not->toContain('2026-10-12');
});

test('a night at the same price with no discount is skipped', function () {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);

    $result = npChange($setup, [$first], '300.00');

    expect($result->changed)->toBe(0)->and($first->refresh()->override_amount)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.night_price_changed')->exists())->toBeFalse();
});

test('a closed or settled folio refuses the change and writes nothing', function (bool $settled, string $refusal) {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));
    $folio = Folio::query()->sole();
    $folio->forceFill(['status' => FolioStatus::Closed])->save();

    if ($settled) {
        ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $folio->id, 'status' => ArInvoiceStatus::Open]);
    }

    $count = FolioLine::query()->count();
    $result = npChange($setup, [$first], '220.00');

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and($first->refresh()->override_amount)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.night_price_changed')->exists())->toBeFalse();
})->with([
    'closed' => [false, PostingResult::REFUSAL_FOLIO_NOT_OPEN],
    'settled' => [true, PostingResult::REFUSAL_FOLIO_SETTLED],
]);

test('price 0 posts a complimentary night with no tax on 0', function () {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));

    npChange($setup, [$first], '0');

    $fix = FolioLine::query()->where('idempotency_key', 'like', 'night:'.$first->id.':fix:1%')->get();
    expect($fix->sum('amount'))->toEqual(0)
        ->and(FolioLine::query()->sum('amount'))->toEqual('0.00')
        ->and($first->refresh()->amount)->toBe('0.00');
});

test('refusals write nothing', function (string $price, string $reason, bool $otherNight, string $refusal) {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);
    $nights = [$first];

    if ($otherNight) {
        $other = ReservationUnit::factory()->for($setup['reservation'])->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-13']);
        $nights[] = $other->nights()->firstOrFail();
    }

    $result = npChange($setup, $nights, $price, $reason);

    expect($result->refusal)->toBe($refusal)
        ->and($first->refresh()->override_amount)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.night_price_changed')->exists())->toBeFalse();
})->with([
    'no reason' => ['220.00', ' ', false, UnitStayResult::REFUSAL_REASON_REQUIRED],
    'bad price' => ['22.505', 'Agreed', false, NightPriceResult::REFUSAL_PRICE_INVALID],
    'night of another unit' => ['220.00', 'Agreed', true, NightPriceResult::REFUSAL_NIGHTS_INVALID],
]);

test('the route changes the price and the preview shows the difference with tax', function (DefaultRoleEnum $role) {
    $setup = npSetup($role);
    [$first, $second] = npNights($setup['unit']);
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));

    $this->actingAs($setup['user'])
        ->postJson(npUrl($setup, 'night-price/preview'), ['night_ids' => [$first->id, $second->id], 'price' => '220.00'])
        ->assertOk()
        ->assertJson([
            'was' => '300.00',
            'correctedDates' => ['2026-10-12'],
            'scheduledDates' => ['2026-10-13'],
            'difference' => '-172.80',
            'error' => null,
        ]);

    $this->actingAs($setup['user'])
        ->post(npUrl($setup), ['night_ids' => [$first->id, $second->id], 'price' => '220.00', 'reason' => 'Agreed'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($second->refresh()->amount)->toBe('220.00');
})->with([DefaultRoleEnum::Owner, DefaultRoleEnum::Manager, DefaultRoleEnum::Finance]);

test('front desk gets 403 and another property gets 404', function () {
    $setup = npSetup(DefaultRoleEnum::FrontDesk);
    [$first] = npNights($setup['unit']);

    $this->actingAs($setup['user'])
        ->post(npUrl($setup), ['night_ids' => [$first->id], 'price' => '220.00', 'reason' => 'Agreed'])
        ->assertForbidden();

    $other = npSetup();
    $outsider = User::factory()->withRole(DefaultRoleEnum::Owner)->create();

    $this->actingAs($outsider)
        ->post("/p/{$outsider->property->code}/reservations/{$other['reservation']->id}/units/{$other['unit']->id}/night-price", ['night_ids' => [$first->id], 'price' => '220.00', 'reason' => 'Agreed'])
        ->assertNotFound();
});

test('the folio tab offers Correct price on a posted room line', function () {
    $setup = npSetup();
    [$first] = npNights($setup['unit']);
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));

    $this->actingAs($setup['user'])
        ->get("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folio")
        ->assertInertia(fn ($page) => $page
            ->where('folio.rows.0.actions', fn ($actions) => in_array('correct_price', collect($actions)->all(), true))
            ->where('folio.rows.0.unitNightId', $first->id)
            ->where('folio.rows.0.unitId', $setup['unit']->id));
});
