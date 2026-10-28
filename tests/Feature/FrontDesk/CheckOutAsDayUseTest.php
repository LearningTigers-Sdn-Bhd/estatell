<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Actions\Catalogues\UpdateDayUseTaxes;
use App\Actions\FrontDesk\CheckOutAsDayUse;
use App\Data\Reservations\CheckOutCollectData;
use App\Enums\ChargingUnit;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\PermissionEnum;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Events\ReservationChanged;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomStatus;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\FrontDesk\CheckOutAsDayUseQuote;
use App\Services\NightAudit\StatisticsCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array<string, mixed> */
function overnightDayUseCheckout(): array
{
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $setup['property']->forceFill(['status' => 'live'])->save();
    $setup['property']->settings->update(['time_zone' => 'Asia/Kuala_Lumpur']);
    $setup['property']->unsetRelation('settings');
    $date = $setup['date']->toDateString();
    test()->travelTo(CarbonImmutable::parse($date.' 18:20:00', 'Asia/Kuala_Lumpur'));
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => $date]);
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id, 'booker_guest_id' => $setup['guest']->id]);
    $unit = ReservationUnit::factory()->checkedIn()->withNights()->create([
        'reservation_id' => $reservation->id,
        'property_id' => $setup['property']->id,
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['basic']->id,
        'arrival_date' => $date,
        'departure_date' => $setup['date']->addDays(2)->toDateString(),
        'arrival_time' => '15:00',
        'checked_in_at' => CarbonImmutable::parse($date.' 14:05:00', 'Asia/Kuala_Lumpur')->utc(),
    ]);
    $unit->nights()->update(['room_id' => $setup['rooms'][0]->id]);
    RoomStatus::query()->where('room_id', $setup['rooms'][0]->id)->update(['status' => HousekeepingStatus::Clean]);

    return [...$setup, 'unit' => $unit, 'reservation' => $reservation];
}

/** @param array<string, mixed> $setup */
function dayUseCheckoutCollect(array $setup, string $amount): CheckOutCollectData
{
    $method = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'requires_reference' => false]);

    return new CheckOutCollectData($method->id, $amount, null, (string) Str::uuid());
}

test('an overnight checkout uses the real check-in, switches plans and posts one idempotent block', function () {
    $s = overnightDayUseCheckout();
    $key = (string) Str::uuid();
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], collect: dayUseCheckoutCollect($s, '120.00'), idempotencyKey: $key);

    expect($result->refusal)->toBeNull();
    $unit = $s['unit']->fresh();
    expect($unit->stay_type)->toBe(StayType::DayUse)
        ->and($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->rate_plan_id)->toBe($s['plan']->id)
        ->and($unit->departure_date->toDateString())->toBe($unit->arrival_date->toDateString())
        ->and($unit->nights()->count())->toBe(0)
        ->and($unit->dayUse->starts_at)->toBe('14:05:00')
        ->and($unit->dayUse->ends_at)->toBe('19:05:00')
        ->and($unit->dayUse->hours)->toBe(5)
        ->and($unit->dayUse->override_amount)->toBeNull()
        ->and($unit->checked_out_at->equalTo(now()))->toBeTrue()
        ->and(Folio::query()->where('reservation_unit_id', $unit->id)->sole()->status)->toBe(FolioStatus::Closed)
        ->and(RoomStatus::query()->where('room_id', $s['rooms'][0]->id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
    $line = FolioLine::query()->where('idempotency_key', 'dayuse:'.$unit->id)->sole();
    expect($line->amount)->toBe('120.00')->and($line->posting_date->toDateString())->toBe($s['date']->toDateString());
    $log = Activity::query()->where('event', 'reservation.unit_checked_out_as_day_use')->sole();
    expect($log->properties['old']['departure_date'])->toBe($s['date']->addDays(2)->toDateString());
    $counts = [FolioLine::count(), Payment::count(), Activity::count()];
    expect(app(CheckOutAsDayUse::class)($s['user'], $s['unit'], idempotencyKey: $key)->refusal)->toBe('invalid_status')
        ->and([FolioLine::count(), Payment::count(), Activity::count()])->toBe($counts);
});

test('no plan keeps the overnight plan but never splits its breakfast inclusion', function (string $amount) {
    $s = overnightDayUseCheckout();
    $s['plan']->update(['archived_at' => now()]);
    expect(app(CheckOutAsDayUse::class)($s['user'], $s['unit'])->refusal)->toBe('amount_required');
    $item = ChargeItem::factory()->create(['property_id' => $s['property']->id]);
    $s['basic']->inclusions()->create([
        'charge_item_id' => $item->id, 'charging_unit' => ChargingUnit::PerGuest, 'quantity' => 1, 'allocated_amount' => '15.00',
        'starts_on' => InclusionStart::ArrivalDay, 'ends_on' => InclusionEnd::DepartureDay,
    ]);
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], amount: $amount, reason: 'Same-day stay', collect: $amount === '0.00' ? null : dayUseCheckoutCollect($s, $amount));

    expect($result->refusal)->toBeNull()
        ->and($s['unit']->fresh()->rate_plan_id)->toBe($s['basic']->id)
        ->and($s['unit']->fresh()->dayUse->override_amount)->toBe($amount)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'dayuse:'.$s['unit']->id.':incl:%')->count())->toBe(0);
})->with(['80.00', '0.00']);

test('a plan price override is recorded and its reason is optional', function () {
    $s = overnightDayUseCheckout();
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], amount: '80.00', reason: 'Guest left early', collect: dayUseCheckoutCollect($s, '80.00'));
    expect($result->refusal)->toBeNull()
        ->and($s['unit']->fresh()->dayUse->override_amount)->toBe('80.00')
        ->and(Activity::query()->where('event', 'reservation.unit_checked_out_as_day_use')->sole()->properties['reason'])->toBe('Guest left early');
});

test('unpriced or unsold hours fall back to no plan and need an amount', function (string $failure) {
    $s = overnightDayUseCheckout();
    if ($failure === 'hours_not_sold') {
        $s['plan']->update(['day_use_max_hours' => 4]);
    } else {
        $s['plan']->rates()->whereDate('date', $s['date']->toDateString())->sole()->hourPrices()->where('hours', 5)->delete();
    }
    expect(app(CheckOutAsDayUse::class)($s['user'], $s['unit'])->refusal)->toBe('amount_required');
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], amount: '80.00', collect: dayUseCheckoutCollect($s, '80.00'));
    expect($result->refusal)->toBeNull()
        ->and($s['unit']->fresh()->rate_plan_id)->toBe($s['basic']->id)
        ->and($s['unit']->fresh()->dayUse->override_amount)->toBe('80.00');
})->with(['hours_not_sold', 'no_hour_price']);

test('the default picks the first plan that prices the stay, skipping a refused one before it', function () {
    $s = overnightDayUseCheckout();
    $s['plan']->update(['day_use_max_hours' => 4]);
    $second = RatePlan::query()->findOrFail($s['plan']->id)->replicate();
    $second->fill(['name' => 'Day Use Second', 'day_use_max_hours' => 8, 'position' => $s['plan']->position + 1])->save();
    $second->roomTypes()->attach($s['roomType']->id);
    $s['plan']->rates()->each(fn ($rate) => $second->rates()->create([...$rate->only(['room_type_id', 'date']), 'stop_sell' => false])->hourPrices()->createMany($rate->hourPrices->map->only(['hours', 'amount'])->all()));

    $data = app(CheckOutAsDayUseQuote::class)->quote($s['unit']);

    expect($data->refusal)->toBeNull()
        ->and($data->ratePlanId)->toBe($second->id)
        ->and($data->planOptions)->toHaveCount(2)
        ->and($data->planOptions[0]->disabledReason)->toBe('Sells 2–4 hours')
        ->and($data->planOptions[1]->disabledReason)->toBeNull()
        ->and($data->planNote)->toBeNull();
});

test('the default falls to no plan with a note and keeps the per-night plan name', function () {
    $s = overnightDayUseCheckout();
    $s['plan']->update(['day_use_max_hours' => 4]);

    $data = app(CheckOutAsDayUseQuote::class)->quote($s['unit'], amount: '50.00');

    expect($data->ratePlanId)->toBeNull()
        ->and($data->planNote)->toBe($s['plan']->name.' sells 2–4 hours.')
        ->and($data->perNightPlanName)->toBe($s['basic']->name)
        ->and($data->planOptions[0]->price)->toBeNull();
});

test('an explicit plan prices the stay and no plan keeps the per-night plan', function () {
    $s = overnightDayUseCheckout();
    $quote = app(CheckOutAsDayUseQuote::class);

    expect($quote->quote($s['unit'], ratePlanId: $s['plan']->id)->ratePlanId)->toBe($s['plan']->id);

    $none = $quote->quote($s['unit'], ratePlanId: $s['plan']->id, noPlan: true);
    expect($none->refusal)->toBe('amount_required')->and($none->ratePlanId)->toBeNull();

    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], amount: '70.00', collect: dayUseCheckoutCollect($s, '70.00'), noPlan: true);
    expect($result->refusal)->toBeNull()->and($s['unit']->fresh()->rate_plan_id)->toBe($s['basic']->id);
});

test('an explicit plan that cannot price the stay refuses with its own refusal on the plan field', function () {
    $s = overnightDayUseCheckout();
    $s['plan']->update(['day_use_max_hours' => 4]);
    $url = route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]);

    expect(app(CheckOutAsDayUse::class)($s['user'], $s['unit'], amount: '80.00', ratePlanId: $s['plan']->id)->refusal)->toBe('hours_not_sold');
    expect($s['unit']->fresh()->status)->toBe(UnitStatus::CheckedIn);
    $this->actingAs($s['user'])->postJson($url, ['rate_plan_id' => $s['plan']->id])
        ->assertJsonPath('refusal', 'hours_not_sold')
        ->assertJsonPath('errors.rate_plan_id', 'This plan does not sell that number of hours.');
});

test('a plan that is not a day use plan on the room type is not linked', function () {
    $s = overnightDayUseCheckout();

    expect(app(CheckOutAsDayUseQuote::class)->quote($s['unit'], ratePlanId: $s['basic']->id)->refusal)->toBe('rate_plan_not_linked');
    $this->actingAs($s['user'])->postJson(route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]), ['rate_plan_id' => $s['plan']->id, 'no_plan' => true])
        ->assertJsonValidationErrors('rate_plan_id');
});

test('without a day use plan the options are empty and an amount is required', function () {
    $s = overnightDayUseCheckout();
    $s['plan']->update(['archived_at' => now()]);

    $data = app(CheckOutAsDayUseQuote::class)->quote($s['unit']);

    expect($data->planOptions)->toBe([])->and($data->planNote)->toBeNull()->and($data->refusal)->toBe('amount_required');
});

test('past midnight refuses before time or amount checks and writes nothing', function () {
    $s = overnightDayUseCheckout();
    $this->travelTo(CarbonImmutable::parse($s['date']->addDay()->toDateString().' 00:01', 'Asia/Kuala_Lumpur'));
    $counts = [FolioLine::count(), Activity::count()];
    expect(app(CheckOutAsDayUse::class)($s['user'], $s['unit'], start: '23:30')->refusal)->toBe('past_midnight')
        ->and($s['unit']->fresh()->stay_type)->toBe(StayType::Overnight)
        ->and($s['unit']->nights()->count())->toBe(2)
        ->and([FolioLine::count(), Activity::count()])->toBe($counts);
});

test('the midnight limit keeps the stored block strictly before midnight', function (string $start, string $end, ?string $refusal, ?int $hours, ?string $blockEnd) {
    $s = overnightDayUseCheckout();
    $this->travelTo(CarbonImmutable::parse($s['date']->toDateString().' 23:50', 'Asia/Kuala_Lumpur'));
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], start: $start, end: $end, amount: '0.00', reason: 'Midnight limit');
    expect($result->refusal)->toBe($refusal);
    if ($refusal === null) {
        expect($s['unit']->fresh()->dayUse->hours)->toBe($hours)
            ->and($s['unit']->fresh()->dayUse->ends_at)->toBe($blockEnd);
    }
})->with([
    ['22:30', '23:40', null, 1, '23:30:00'],
    ['22:00', '23:40', null, 1, '23:00:00'],
    ['22:59', '23:50', null, 1, '23:59:00'],
    ['23:00', '23:40', 'start_time_invalid', null, null],
    ['23:01', '23:40', 'start_time_invalid', null, null],
]);

test('a refused refund rolls back the conversion, posting and logs', function () {
    $s = overnightDayUseCheckout();
    $folio = Folio::factory()->forUnit($s['unit'])->create(['property_id' => $s['property']->id, 'reservation_id' => $s['reservation']->id]);
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '200.00']);
    $before = [FolioLine::count(), Payment::count(), Activity::count()];
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], refundAmount: '201.00', idempotencyKey: (string) Str::uuid());
    expect($result->refusal)->toBe('refund_exceeds_payment')
        ->and($s['unit']->fresh()->stay_type)->toBe(StayType::Overnight)
        ->and($s['unit']->fresh()->rate_plan_id)->toBe($s['basic']->id)
        ->and($s['unit']->nights()->count())->toBe(2)
        ->and($s['unit']->dayUse()->exists())->toBeFalse()
        ->and([FolioLine::count(), Payment::count(), Activity::count()])->toBe($before);
});

test('an unsettled balance rolls back even an accepted refund', function () {
    $s = overnightDayUseCheckout();
    $folio = Folio::factory()->forUnit($s['unit'])->create(['property_id' => $s['property']->id, 'reservation_id' => $s['reservation']->id]);
    Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '200.00']);
    $before = [FolioLine::count(), Payment::count(), Activity::count()];
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], refundAmount: '70.00', idempotencyKey: (string) Str::uuid());
    expect($result->refusal)->toBe('balance_not_settled')
        ->and($s['unit']->fresh()->stay_type)->toBe(StayType::Overnight)
        ->and($s['unit']->nights()->count())->toBe(2)
        ->and($s['unit']->dayUse()->exists())->toBeFalse()
        ->and([FolioLine::count(), Payment::count(), Activity::count()])->toBe($before);
});

test('the preview writes nothing and matches the posted checkout and day use statistics', function () {
    $s = overnightDayUseCheckout();
    $before = [FolioLine::count(), Payment::count(), Activity::count()];
    $url = route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]);
    $this->actingAs($s['user'])->postJson($url)->assertOk()
        ->assertJsonPath('hours', 5)->assertJsonPath('start', '14:05:00')->assertJsonPath('blockEnd', '19:05:00')
        ->assertJsonPath('checkedInTime', '14:05')->assertJsonPath('ratePlanName', $s['plan']->name)
        ->assertJsonPath('noTaxes', true)->assertJsonPath('errors', [])
        ->assertJsonPath('settlement.balance', '120.00')->assertJsonPath('settlement.owing', '120.00');
    expect([FolioLine::count(), Payment::count(), Activity::count()])->toBe($before)
        ->and($s['unit']->dayUse()->exists())->toBeFalse()
        ->and($s['unit']->nights()->count())->toBe(2);
    $method = PaymentMethod::factory()->create(['property_id' => $s['property']->id, 'requires_reference' => false]);
    $this->post(route('reservations.units.check-out-as-day-use', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]), [
        'collect_amount' => '120.00', 'payment_method_id' => $method->id, 'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Unit checked out as day use.');
    expect($s['unit']->fresh()->status)->toBe(UnitStatus::CheckedOut);
    $figures = app(StatisticsCalculator::class)->forDate($s['property'], $s['date']);
    expect($figures->dayUseSold)->toBe(1)->and((string) $figures->dayUseRevenue->getAmount())->toBe('120.00')
        ->and($figures->roomsSold)->toBe(0)->and($figures->occupancy)->toBe('0.00')
        ->and((string) $figures->adr->getAmount())->toBe('0.00')->and((string) $figures->revpar->getAmount())->toBe('0.00');
});

test('the shared quote preserves refusal order and the action writes nothing', function (string $scenario, string $refusal) {
    $s = overnightDayUseCheckout();
    $unit = $s['unit'];
    match ($scenario) {
        'status' => $unit->forceFill(['status' => UnitStatus::Confirmed])->save(),
        'day_use' => $unit->forceFill(['stay_type' => StayType::DayUse, 'departure_date' => $unit->arrival_date])->save(),
        'arrival' => $unit->update(['arrival_date' => $unit->arrival_date->subDay()]),
        'business_date' => BusinessDate::query()->where('property_id', $s['property']->id)->update(['date' => $s['date']->addDay()->toDateString()]),
        'check_in_date' => $unit->forceFill(['checked_in_at' => $unit->checked_in_at->subDay()])->save(),
        'end' => null,
    };
    $before = [FolioLine::count(), Payment::count(), Activity::count(), $unit->nights()->count()];
    $end = $scenario === 'end' ? '14:00' : null;
    $preview = app(CheckOutAsDayUseQuote::class)->preview($unit->fresh(), $s['user'], end: $end);
    $result = app(CheckOutAsDayUse::class)($s['user'], $unit, end: $end);
    expect($preview->refusal)->toBe($refusal)->and($result->refusal)->toBe($refusal)
        ->and([FolioLine::count(), Payment::count(), Activity::count(), $unit->nights()->count()])->toBe($before);
})->with([
    ['status', 'invalid_status'], ['day_use', 'day_use_unit'], ['arrival', 'not_same_day'],
    ['business_date', 'not_same_day'], ['check_in_date', 'start_time_invalid'],
    ['end', 'hours_invalid'],
]);

test('a plan inclusion and DAY_USE tax match preview, and the existing payment funds the refund', function () {
    $s = overnightDayUseCheckout();
    $item = ChargeItem::factory()->create(['property_id' => $s['property']->id]);
    $inclusion = $s['plan']->inclusions()->create([
        'charge_item_id' => $item->id, 'charging_unit' => ChargingUnit::PerGuest, 'quantity' => 1, 'allocated_amount' => '15.00',
        'starts_on' => InclusionStart::ArrivalDay, 'ends_on' => InclusionEnd::DepartureDay,
    ]);
    $s['property']->taxes()->update(['enabled' => true]);
    $sst = $s['property']->taxes()->where('code', 'SST')->sole();
    app(UpdateDayUseTaxes::class)($s['property'], $s['user'], [$sst->id]);
    $s['plan']->rates()->whereDate('date', $s['date']->toDateString())->update(['stop_sell' => true]);
    $folio = Folio::factory()->forUnit($s['unit'])->create(['property_id' => $s['property']->id, 'reservation_id' => $s['reservation']->id]);
    $payment = Payment::factory()->create(['folio_id' => $folio->id, 'amount' => '200.00']);
    $original = $payment->folioLine->getAttributes();
    $preview = app(CheckOutAsDayUseQuote::class)->preview($s['unit'], $s['user']);
    expect($preview->refusal)->toBeNull()
        ->and($preview->noTaxes)->toBeFalse()
        ->and($preview->settlement->refundDefault)->toBe('71.60')
        ->and($preview->folioLines[0]->amount)->toBe('-200.00')
        ->and($preview->folioLines[array_key_last($preview->folioLines)]->description)->toBe('Refund to guest');
    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], refundAmount: $preview->settlement->refundDefault, idempotencyKey: (string) Str::uuid());
    expect($result->refusal)->toBeNull()
        ->and(FolioLine::query()->where('idempotency_key', 'dayuse:'.$s['unit']->id)->sole()->amount)->toBe('105.00')
        ->and(FolioLine::query()->where('idempotency_key', 'dayuse:'.$s['unit']->id.':incl:'.$inclusion->id)->sole()->amount)->toBe('15.00')
        ->and($payment->folioLine->fresh()->getAttributes())->toBe($original)
        ->and(Payment::query()->where('refund_of_id', $payment->id)->sole()->amount)->toBe('71.60');
});

test('both routes require manage and checkout permissions and isolate properties', function (string $suffix) {
    $s = overnightDayUseCheckout();
    $args = ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']];
    $url = route('reservations.units.check-out-as-day-use'.$suffix, $args);
    $this->post($url)->assertRedirect();
    foreach ([PermissionEnum::ReservationsManage, PermissionEnum::FrontDeskCheckOut] as $permission) {
        $staff = User::factory()->for($s['property'])->withPermissions([$permission])->create();
        $this->actingAs($staff)->postJson($url)->assertForbidden();
    }
    $outsider = User::factory()->withPermissions([PermissionEnum::ReservationsManage, PermissionEnum::FrontDeskCheckOut])->create();
    $this->actingAs($outsider)->postJson($url)->assertNotFound();
    $this->actingAs($s['user'])->postJson($url, ['amount' => '-1', 'start' => '25:00'])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'start']);
})->with(['', '.preview']);

test('the preview sends inline errors and manual price fields without a plan', function () {
    $s = overnightDayUseCheckout();
    $s['plan']->update(['archived_at' => now()]);
    $url = route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]);

    $this->actingAs($s['user'])->postJson($url)
        ->assertOk()
        ->assertJsonPath('ratePlanId', null)
        ->assertJsonPath('planPrice', null)
        ->assertJsonPath('noTaxes', true)
        ->assertJsonPath('errors.amount', 'Enter an amount of 0 or more for this day use.');
    $this->postJson($url, ['amount' => '0.00'])
        ->assertJsonPath('refusal', null)
        ->assertJsonPath('errors', []);
    $this->postJson($url, ['amount' => '0.00', 'reason' => 'No charge'])
        ->assertJsonPath('refusal', null)
        ->assertJsonPath('amount', '0.00')
        ->assertJsonPath('settlement.balance', '0.00');
});

test('invalid time previews keep the editable defaults and explain the field refusal', function () {
    $s = overnightDayUseCheckout();

    $this->actingAs($s['user'])->postJson(route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]), ['end' => '14:00'])
        ->assertJsonPath('start', '14:05:00')
        ->assertJsonPath('end', '14:00:00')
        ->assertJsonPath('errors.end', 'Choose an end time after the start on the business date.');
});

test('the group page sends no dialog action without permission to change the stay', function () {
    $s = overnightDayUseCheckout();
    $staff = User::factory()->for($s['property'])->withPermissions([PermissionEnum::ReservationsView, PermissionEnum::FrontDeskCheckOut])->create();

    $this->actingAs($staff)->get(route('reservations.check-out', ['property' => $s['property'], 'reservation' => $s['reservation']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checkOut.preview.rows.0.checkOutAction', null)
            ->where('checkOut.preview.rows.0.unit', null)
            ->where('checkOut.preview.rows.0.note', 'Arrived today.'));
});

test('the day use menu item disappears when the business date moves on', function () {
    $s = overnightDayUseCheckout();
    $this->travelTo(now()->addDay());
    BusinessDate::query()->where('property_id', $s['property']->id)->update(['date' => $s['date']->addDay()->toDateString()]);

    $this->actingAs($s['user'])->get(route('reservations.show', ['property' => $s['property'], 'reservation' => $s['reservation']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unit.actions.0.key', 'check-out')
            ->where('unit.actions.0.label', 'Check out early…')
            ->where('unit.actions.0.disabled', false)
            ->where('unit.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('check-out-as-day-use')));
});

test('same-day menu props follow the property midnight and keep normal check-out visible', function (string $localTime, bool $pastMidnight, string $label, bool $disabled) {
    $s = overnightDayUseCheckout();
    $this->travelTo(CarbonImmutable::parse($s['date']->toDateString().' '.$localTime, 'Asia/Kuala_Lumpur'));

    $this->actingAs($s['user'])->get(route('reservations.show', ['property' => $s['property'], 'reservation' => $s['reservation']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unit.actions.0.key', 'check-out')
            ->where('unit.actions.0.label', $label)
            ->where('unit.actions.0.disabled', $disabled)
            ->where('unit.actions.1.key', 'check-out-as-day-use')
            ->where('unit.actions.1.disabled', $pastMidnight)
            ->where('unit.actions.1.note', $pastMidnight ? 'The guest stayed past midnight, so this is a night.' : null)
            ->where('singleUnit.actions', fn ($actions) => collect($actions)->contains(fn ($item) => $item['key'] === 'check-out-as-day-use' && $item['disabled'] === $pastMidnight)));
})->with([
    'before midnight' => ['23:59:59', false, 'Check out', true],
    'at midnight' => ['24:00:00', true, 'Check out early…', false],
]);

test('the menu needs both permissions to offer day use', function (array $permissions) {
    $s = overnightDayUseCheckout();
    $staff = User::factory()->for($s['property'])->withPermissions([PermissionEnum::ReservationsView, ...$permissions])->create();

    $this->actingAs($staff)->get(route('reservations.show', ['property' => $s['property'], 'reservation' => $s['reservation']]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('check-out-as-day-use')));
})->with([
    'check-out only' => [[PermissionEnum::FrontDeskCheckOut]],
    'manage only' => [[PermissionEnum::ReservationsManage]],
]);

test('group page props open the day use dialog before midnight and early check-out after it', function (string $localTime, string $action, string $note) {
    $s = overnightDayUseCheckout();
    $this->travelTo(CarbonImmutable::parse($s['date']->toDateString().' '.$localTime, 'Asia/Kuala_Lumpur'));

    $this->actingAs($s['user'])->get(route('reservations.check-out', ['property' => $s['property'], 'reservation' => $s['reservation']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checkOut.preview.rows.0.unit.id', $s['unit']->id)
            ->where('checkOut.preview.rows.0.checkOutAction.key', $action)
            ->where('checkOut.preview.rows.0.checkOutAction.disabled', false)
            ->where('checkOut.preview.rows.0.selectable', false)
            ->where('checkOut.preview.rows.0.note', $note));
})->with([
    'same day' => ['23:59:59', 'check-out-as-day-use', 'Arrived today.'],
    'past midnight' => ['24:00:00', 'check-out', 'Departs 15 Nov'],
]);

test('unposted add-ons of the dropped nights are previewed and removed, and the freed nights reach the channels', function () {
    $s = overnightDayUseCheckout();
    Event::fake([ReservationChanged::class]);
    $today = $s['date']->toDateString();
    $tomorrow = $s['date']->addDay()->toDateString();
    $breakfast = ChargeItem::factory()->create(['property_id' => $s['property']->id, 'name' => 'Breakfast', 'charging_unit' => ChargingUnit::PerNight]);
    $transfer = ChargeItem::factory()->create(['property_id' => $s['property']->id, 'name' => 'Transfer', 'charging_unit' => ChargingUnit::PerStay]);
    $dropped = [
        UnitAddOn::factory()->create(['reservation_unit_id' => $s['unit']->id, 'charge_item_id' => $breakfast->id, 'quantity' => 2, 'service_date' => $today]),
        UnitAddOn::factory()->create(['reservation_unit_id' => $s['unit']->id, 'charge_item_id' => $breakfast->id, 'quantity' => 2, 'service_date' => $tomorrow]),
        UnitAddOn::factory()->create(['reservation_unit_id' => $s['unit']->id, 'charge_item_id' => $transfer->id, 'service_date' => $tomorrow]),
    ];
    $kept = UnitAddOn::factory()->create(['reservation_unit_id' => $s['unit']->id, 'charge_item_id' => $transfer->id, 'service_date' => $today]);

    $this->actingAs($s['user'])->postJson(route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]))
        ->assertOk()
        ->assertJsonPath('droppedAddOns', [
            'Breakfast × 2 · '.$s['date']->format('j M'),
            'Breakfast × 2 · '.$s['date']->addDay()->format('j M'),
            'Transfer × 1 · '.$s['date']->addDay()->format('j M'),
        ]);
    expect(UnitAddOn::query()->where('reservation_unit_id', $s['unit']->id)->count())->toBe(4);
    Event::assertNotDispatched(ReservationChanged::class);

    $result = app(CheckOutAsDayUse::class)($s['user'], $s['unit'], collect: dayUseCheckoutCollect($s, '120.00'));

    expect($result->refusal)->toBeNull()
        ->and(UnitAddOn::query()->whereKey(array_map(fn (UnitAddOn $addOn): int => $addOn->id, $dropped))->exists())->toBeFalse()
        ->and($kept->fresh())->not->toBeNull();
    Event::assertDispatchedTimes(ReservationChanged::class, 1);
    Event::assertDispatched(ReservationChanged::class, fn (ReservationChanged $event): bool => $event->propertyId === $s['property']->id
        && $event->roomTypeId === $s['roomType']->id
        && $event->dateFrom === $today
        && $event->dateTo === $tomorrow);
});

test('a dorm bed unit cannot check out as day use and the menu does not offer it', function () {
    $s = overnightDayUseCheckout();
    $s['roomType']->update(['sell_unit' => SellUnit::Bed]);
    $counts = [FolioLine::count(), Activity::count()];

    expect(app(CheckOutAsDayUse::class)($s['user'], $s['unit']->fresh(), amount: '80.00', reason: 'Same-day stay')->refusal)->toBe('bed_unit')
        ->and($s['unit']->fresh()->stay_type)->toBe(StayType::Overnight)
        ->and($s['unit']->fresh()->nights()->count())->toBe(2)
        ->and([FolioLine::count(), Activity::count()])->toBe($counts);
    $this->actingAs($s['user'])->postJson(route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]))
        ->assertJsonPath('refusal', 'bed_unit')
        ->assertJsonPath('errors.form', 'A dorm bed cannot check out as day use. Use Undo check-in or check out on the departure date.');
    $this->get(route('reservations.show', ['property' => $s['property'], 'reservation' => $s['reservation']]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('check-out-as-day-use')));
});

test('the quote prices whole minutes and ignores seconds', function () {
    $s = overnightDayUseCheckout();
    $s['unit']->forceFill(['checked_in_at' => CarbonImmutable::parse($s['date']->toDateString().' 14:00:40', 'Asia/Kuala_Lumpur')->utc()])->save();
    $url = route('reservations.units.check-out-as-day-use.preview', ['property' => $s['property'], 'reservation' => $s['reservation'], 'unit' => $s['unit']]);

    $this->actingAs($s['user'])->postJson($url, ['end' => '15:00:30', 'amount' => '1.00', 'reason' => 'Whole minutes'])->assertOk()
        ->assertJsonPath('start', '14:00:00')->assertJsonPath('end', '15:00:00')
        ->assertJsonPath('hours', 1)->assertJsonPath('blockEnd', '15:00:00');
});
