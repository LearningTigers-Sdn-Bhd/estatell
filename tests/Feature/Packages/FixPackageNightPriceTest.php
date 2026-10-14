<?php

use App\Actions\NightAudit\FixPackageNightPrice;
use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\AuditCheck;
use App\Enums\AuditExceptionType;
use App\Enums\ChargingUnit;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\PricingBasis;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\NightAudit\AuditReadiness;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * The old Dive Package split: 2 adults, 3 nights, with night 2 already charged and night 1 stuck.
 *
 * @param  list<PermissionEnum>  $permissions
 * @return array{property: Property, user: User, unit: ReservationUnit, exception: NightAuditException}
 */
function packageFixSetup(array $permissions = [PermissionEnum::NightAuditRun, PermissionEnum::ReservationsManage], bool $override = false): array
{
    test()->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    $audited = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-08']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-11']);
    $plan = RatePlan::factory()->for($property)->package(nights: 3)->create(['name' => 'Dive Package']);
    RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '100.00']);
    RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerGuest, 'quantity' => 2, 'allocated_amount' => '180.00']);
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->checkedIn()->create([
        'rate_plan_id' => $plan->id, 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-11', 'adults' => 2,
    ]);
    $room = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '202']);

    foreach ($unit->nights()->orderBy('date')->get() as $index => $night) {
        $amount = ['586.67', '586.67', '586.66'][$index];
        $night->forceFill(['room_id' => $room->id, 'base_amount' => $amount, 'amount' => $amount, 'override_amount' => $override ? $amount : null])->save();
    }

    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $user = User::factory()->for($property)->withPermissions($permissions)->create();
    expect(app(PostRoomCharges::class)($user, $unit, CarbonImmutable::parse('2026-10-09'))->isRefused())->toBeFalse();
    $audit = NightAudit::factory()->completed()->create(['business_date_id' => $audited->id]);
    $exception = NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $unit->id, 'message' => 'exceeds_night_amount']);

    return compact('property', 'user', 'unit', 'exception');
}

function packageFixUrl(array $setup, bool $preview = false): string
{
    return route('night-audit.exceptions.fix-price'.($preview ? '.preview' : ''), [$setup['property']->code, $setup['exception']]);
}

test('Fix price previews stored night amounts, leaves charged nights alone, posts night 1 and keeps 1,760.00', function (bool $override) {
    $setup = packageFixSetup(override: $override);
    $before = $setup['unit']->nights()->orderBy('date')->get();
    $charged = $before[1]->getAttributes();
    $lineCount = FolioLine::query()->count();

    $this->actingAs($setup['user'])->postJson(packageFixUrl($setup, true))->assertOk()
        ->assertJsonPath('title', 'Fix package price · Room 202 · Dive Package')
        ->assertJsonPath('total', '1760.00')
        ->assertJsonPath('nights.0.oldAmount', '586.67')->assertJsonPath('nights.0.newAmount', '946.67')
        ->assertJsonPath('nights.1.oldAmount', '586.66')->assertJsonPath('nights.1.newAmount', '226.66')
        ->assertJsonPath('chargedNights.0.oldAmount', '586.67');
    expect(FolioLine::query()->count())->toBe($lineCount)
        ->and($setup['unit']->nights()->orderBy('date')->pluck('amount')->all())->toBe(['586.67', '586.67', '586.66'])
        ->and($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);

    $this->postJson(packageFixUrl($setup))->assertOk()->assertJsonPath('done', 1);

    $after = $setup['unit']->nights()->orderBy('date')->get();
    expect($after->pluck('amount')->all())->toBe(['946.67', '586.67', '226.66'])
        ->and($after[1]->getAttributes())->toBe($charged)
        ->and((string) $setup['unit']->nights()->sum('amount'))->toBe('1760.00')
        ->and($after[0]->override_amount)->toBe($override ? '946.67' : null)
        ->and($after[2]->override_amount)->toBe($override ? '226.66' : null)
        ->and($setup['exception']->refresh()->resolved_by)->toBe($setup['user']->id);
    $this->assertDatabaseHas('folio_lines', ['idempotency_key' => 'night:'.$after[0]->id, 'amount' => '26.67']);
    $activity = Activity::query()->where('event', 'reservation.package_nights_respread')->sole();
    expect($activity->subject_id)->toBe($setup['unit']->reservation_id)
        ->and($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->properties['nights'][0]['old_amount'])->toBe('586.67')
        ->and($activity->properties['nights'][0]['new_amount'])->toBe('946.67');

    $posted = FolioLine::query()->count();
    $this->postJson(packageFixUrl($setup))->assertUnprocessable();
    expect(FolioLine::query()->count())->toBe($posted)
        ->and(Activity::query()->where('event', 'reservation.package_nights_respread')->count())->toBe(1);
})->with(['quoted nights' => false, 'override nights' => true]);

test('Fix price splits the uncharged extras by the new amounts and preserves the extra total', function () {
    $setup = packageFixSetup();
    foreach ($setup['unit']->nights()->orderBy('date')->get() as $index => $night) {
        $night->forceFill(['base_amount' => '528.00', 'extra_amount' => ['58.67', '58.67', '58.66'][$index]])->save();
    }

    expect(app(FixPackageNightPrice::class)($setup['user'], $setup['exception'])->isRefused())->toBeFalse();

    $nights = $setup['unit']->nights()->orderBy('date')->get();
    expect($nights->pluck('base_amount')->all())->toBe(['852.01', '528.00', '203.99'])
        ->and($nights->pluck('extra_amount')->all())->toBe(['94.66', '58.67', '22.67']);
});

test('Fix price spreads the whole stay and corrects the charged nights when the nights left cannot cover their inclusions', function () {
    $setup = packageFixSetup([PermissionEnum::NightAuditRun, PermissionEnum::ReservationsManage, PermissionEnum::ReservationsChangePrice]);
    expect(app(PostRoomCharges::class)($setup['user'], $setup['unit'], CarbonImmutable::parse('2026-10-10'))->isRefused())->toBeFalse();
    $nights = $setup['unit']->nights()->orderBy('date')->get();

    $this->actingAs($setup['user'])->postJson(packageFixUrl($setup, true))->assertOk()
        ->assertJsonPath('wholeStay', true)
        ->assertJsonPath('blockedReason', null)
        ->assertJsonPath('chargedNights', [])
        ->assertJsonPath('nights.0.newAmount', '1066.67')->assertJsonPath('nights.0.charged', false)
        ->assertJsonPath('nights.1.newAmount', '346.67')->assertJsonPath('nights.1.charged', true)
        ->assertJsonPath('nights.2.newAmount', '346.66')->assertJsonPath('nights.2.charged', true);

    $this->postJson(packageFixUrl($setup))->assertOk()->assertJsonPath('done', 1);

    expect($setup['unit']->nights()->orderBy('date')->pluck('amount')->all())->toBe(['1066.67', '346.67', '346.66'])
        ->and((string) $setup['unit']->nights()->sum('amount'))->toBe('1760.00')
        ->and($setup['exception']->refresh()->resolved_by)->toBe($setup['user']->id);
    $this->assertDatabaseHas('folio_lines', ['idempotency_key' => 'night:'.$nights[0]->id, 'amount' => '146.67']);

    foreach ([$nights[1], $nights[2]] as $night) {
        expect(FolioLine::query()->where('idempotency_key', 'like', 'night:'.$night->id.':fix:%')->exists())->toBeTrue();
    }

    expect(Activity::query()->where('event', 'reservation.night_price_changed')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'reservation.package_nights_respread')->sole()->properties['whole_stay'])->toBeTrue();
});

test('Fix price needs Change price to correct charged nights and writes nothing without it', function () {
    $setup = packageFixSetup();
    $setup['unit']->nights()->whereDate('date', '2026-10-10')->update(['amount' => '400.00', 'base_amount' => '400.00']);
    $before = $setup['unit']->nights()->orderBy('date')->get()->toArray();
    $count = FolioLine::query()->count();

    $this->actingAs($setup['user'])->postJson(packageFixUrl($setup, true))->assertOk()
        ->assertJsonPath('wholeStay', true)
        ->assertJsonPath('blockedReason', 'Correcting charged nights needs the Change price permission.');
    $this->postJson(packageFixUrl($setup))->assertUnprocessable()
        ->assertJsonPath('message', 'Correcting charged nights needs the Change price permission.');

    expect($setup['unit']->nights()->orderBy('date')->get()->toArray())->toBe($before)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('Fix price refuses when the whole stay cannot cover its inclusions and writes nothing', function () {
    $setup = packageFixSetup([PermissionEnum::NightAuditRun, PermissionEnum::ReservationsManage, PermissionEnum::ReservationsChangePrice]);
    $setup['unit']->nights()->whereDate('date', '2026-10-10')->update(['amount' => '100.00', 'base_amount' => '100.00']);
    $before = $setup['unit']->nights()->orderBy('date')->get()->toArray();
    $count = FolioLine::query()->count();

    $this->actingAs($setup['user'])->postJson(packageFixUrl($setup, true))->assertUnprocessable()
        ->assertJsonPath('message', "The package price left (MYR 1,273.34) cannot cover these nights' inclusions. Change the price with Change price.");
    expect(app(FixPackageNightPrice::class)($setup['user'], $setup['exception'], true)->refusal)->toBe('package_below_inclusions')
        ->and($setup['unit']->nights()->orderBy('date')->get()->toArray())->toBe($before)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('Fix price rolls back new prices when Retry refuses the closed folio', function () {
    $setup = packageFixSetup();
    Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->forceFill(['status' => FolioStatus::Closed])->save();
    $before = $setup['unit']->nights()->orderBy('date')->get()->toArray();
    $count = FolioLine::query()->count();

    $this->actingAs($setup['user'])->postJson(packageFixUrl($setup))->assertUnprocessable()
        ->assertJsonPath('message', 'The folio was closed, so the night could not be charged.');

    expect($setup['unit']->nights()->orderBy('date')->get()->toArray())->toBe($before)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('Fix price checks open room charge, package and remaining inclusions in that order', function (array $exceptionChanges, bool $nightly, string $refusal) {
    $setup = packageFixSetup();
    $setup['exception']->forceFill($exceptionChanges)->save();
    if ($nightly) {
        $setup['unit']->ratePlan->forceFill(['pricing_basis' => PricingBasis::PerRoom, 'fixed_nights' => null, 'package_unit' => null])->save();
    }
    $before = $setup['unit']->nights()->orderBy('date')->get()->toArray();
    $count = FolioLine::query()->count();

    expect(app(FixPackageNightPrice::class)($setup['user'], $setup['exception'])->refusal)->toBe($refusal)
        ->and($setup['unit']->nights()->orderBy('date')->get()->toArray())->toBe($before)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'resolved before wrong message' => [['resolved_at' => '2026-10-11', 'message' => 'folio_not_open'], false, 'exception_not_retried'],
    'another exception type' => [['type' => AuditExceptionType::MissedArrival], false, 'exception_not_retried'],
    'another refusal' => [['message' => 'folio_not_open'], false, 'not_package_night'],
    'nightly plan' => [[], true, 'not_package_night'],
]);

test('when the real first night is charged the next uncharged night gets no one-off inclusions', function () {
    $setup = packageFixSetup();
    $nights = $setup['unit']->nights()->orderBy('date')->get();
    $nights[0]->forceFill(['amount' => '946.67', 'base_amount' => '946.67'])->save();
    $nights[2]->forceFill(['amount' => '226.66', 'base_amount' => '226.66'])->save();
    expect(app(PostRoomCharges::class)($setup['user'], $setup['unit'], CarbonImmutable::parse('2026-10-08'))->isRefused())->toBeFalse();
    $thirdAudit = NightAudit::factory()->completed()->create(['business_date_id' => BusinessDate::factory()->closed()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-10'])->id]);
    $exception = NightAuditException::factory()->create(['night_audit_id' => $thirdAudit->id, 'reservation_unit_id' => $setup['unit']->id, 'message' => 'exceeds_night_amount']);

    $this->actingAs($setup['user'])->postJson(route('night-audit.exceptions.fix-price.preview', [$setup['property']->code, $exception]))
        ->assertOk()->assertJsonCount(1, 'nights')->assertJsonPath('nights.0.newAmount', '226.66');
    expect(app(FixPackageNightPrice::class)($setup['user'], $exception)->isRefused())->toBeFalse();
    $this->assertDatabaseHas('folio_lines', ['idempotency_key' => 'night:'.$nights[2]->id, 'amount' => '26.66']);
});

test('Fix price and preview require reservations.manage and the exception property', function () {
    $setup = packageFixSetup([PermissionEnum::NightAuditRun]);
    $this->actingAs($setup['user'])->postJson(packageFixUrl($setup))->assertForbidden();
    $this->postJson(packageFixUrl($setup, true))->assertForbidden();
    $owner = packageFixSetup();
    foreach (['night-audit.exceptions.fix-price', 'night-audit.exceptions.fix-price.preview'] as $route) {
        $this->actingAs($owner['user'])->postJson(route($route, [$owner['property']->code, $setup['exception']]))->assertNotFound();
    }
    expect($setup['exception']->refresh()->resolved_at)->toBeNull();
});

test('the sheet replaces Retry with Fix price for package failures and offers Dismiss on every carried unit', function () {
    $setup = packageFixSetup();
    $other = NightAuditException::factory()->create(['night_audit_id' => $setup['exception']->night_audit_id, 'reservation_unit_id' => $setup['unit']->id, 'message' => 'folio_not_open']);

    $items = collect(app(AuditReadiness::class)->items($setup['property']))->where('check', AuditCheck::CarriedException);
    expect($items->pluck('exceptionId')->all())->toBe([$setup['exception']->id, $other->id])
        ->and($items->pluck('canFixPrice')->all())->toBe([true, false]);
    $warnings = collect($this->actingAs($setup['user'])->getJson(route('night-audit.show', $setup['property']->code))->json('warnings'))->where('check', 'carried_exception')->first();
    expect(array_column($warnings['units'][0]['actions'], 'label'))->toBe(['Fix price', 'Dismiss'])
        ->and(array_column($warnings['units'][1]['actions'], 'label'))->toBe(['Retry', 'Dismiss']);
});
