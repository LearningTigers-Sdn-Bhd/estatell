<?php

require_once __DIR__.'/../Support/CommissionSetup.php';

use App\Actions\Commissions\CancelCommissionPeriod;
use App\Actions\Commissions\RecordDueCommissions;
use App\Actions\Commissions\StartCommissionPeriod;
use App\Actions\Commissions\StopCommissionPeriod;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\CommissionEntry;
use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function periodAdmin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m-d', $date);
}

test('a booking engine booking with no period records the money but no commission', function () {
    $booking = commissionBooking();
    PropertyCommissionPeriod::query()->delete();
    commissionFolioLine($booking['reservation'], SystemTransactionCode::RoomRevenue, '200.00');
    endCommissionStay($booking['reservation']);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->pluck('type')->all())->toBe([CommissionEntryType::Collected]);
});

test('a booking is charged when its check-out date is inside the period, end date included', function (string $startsOn, ?string $endsOn, int $entries) {
    $reservation = channelCommissionReservation(chargedFrom: null);
    startCommission($reservation->property_id, CommissionService::ChannelManager, $startsOn, $endsOn);
    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '300.00');
    endCommissionStay($reservation);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->count())->toBe($entries);
})->with([
    'stopped on the check-out date' => ['2026-10-01', '2026-10-14', 1],
    'stopped the day before' => ['2026-10-01', '2026-10-13', 0],
    'open period' => ['2026-10-14', null, 1],
]);

test('a period that starts after a booking ended never charges it', function () {
    $reservation = channelCommissionReservation(chargedFrom: null);
    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '300.00');
    endCommissionStay($reservation);
    app(RecordDueCommissions::class)();

    startCommission($reservation->property_id, CommissionService::ChannelManager, '2026-10-20');
    $this->travelTo('2026-10-21 12:00:00');
    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->count())->toBe(0);
});

test('a booking charged before a stop is still corrected after it', function () {
    $reservation = channelCommissionReservation();
    commissionFolioLine($reservation, SystemTransactionCode::CancellationRevenue, '200.00');
    endCommissionStay($reservation, UnitStatus::Cancelled);
    app(RecordDueCommissions::class)();

    PropertyCommissionPeriod::query()->update(['ends_on' => '2026-10-14']);
    commissionFolioLine($reservation, SystemTransactionCode::CancellationRevenue, '-200.00');
    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->orderBy('id')->pluck('amount')->all())->toBe(['-6.00', '6.00']);
});

test('starting a period logs it, and the date, an open period or an overlap refuses it', function () {
    $property = Property::factory()->create();
    $admin = periodAdmin();
    $start = app(StartCommissionPeriod::class);

    expect($start($admin, $property, CommissionService::ChannelManager, day('2026-10-13')))->toBe(StartCommissionPeriod::REFUSAL_PAST_DATE)
        ->and($start($admin, $property, CommissionService::ChannelManager, day('2026-11-01')))->toBeNull()
        ->and($start($admin, $property, CommissionService::ChannelManager, day('2026-12-01')))->toBe(StartCommissionPeriod::REFUSAL_ALREADY_OPEN)
        ->and($start($admin, $property, CommissionService::BookingEngine, day('2026-10-14')))->toBeNull();

    PropertyCommissionPeriod::query()->where('service', CommissionService::ChannelManager)->update(['ends_on' => '2026-11-30']);

    expect($start($admin, $property, CommissionService::ChannelManager, day('2026-11-30')))->toBe(StartCommissionPeriod::REFUSAL_OVERLAPS)
        ->and($start($admin, $property, CommissionService::ChannelManager, day('2026-12-01')))->toBeNull()
        ->and(PropertyCommissionPeriod::query()->count())->toBe(3)
        ->and(Activity::query()->where('description', 'Commission started')->count())->toBe(3);
});

test('stopping a period sets its end, and a past date, an earlier date or a stopped period refuses it', function () {
    $period = startCommission(Property::factory()->create()->id, CommissionService::BookingEngine, '2026-11-01');
    $admin = periodAdmin();
    $stop = app(StopCommissionPeriod::class);

    expect($stop($admin, $period, day('2026-10-13')))->toBe(StopCommissionPeriod::REFUSAL_PAST_DATE)
        ->and($stop($admin, $period, day('2026-10-20')))->toBe(StopCommissionPeriod::REFUSAL_BEFORE_START)
        ->and(Activity::query()->count())->toBe(0)
        ->and($stop($admin, $period, day('2026-11-30')))->toBeNull()
        ->and($period->refresh()->ends_on->toDateString())->toBe('2026-11-30')
        ->and($stop($admin, $period, day('2026-12-31')))->toBe(StopCommissionPeriod::REFUSAL_NOT_OPEN)
        ->and(Activity::query()->sole()->description)->toBe('Commission stopped');
});

test('only a period that has not started can be cancelled', function () {
    $propertyId = Property::factory()->create()->id;
    $started = startCommission($propertyId, CommissionService::BookingEngine, '2026-10-14');
    $future = startCommission($propertyId, CommissionService::ChannelManager, '2026-10-15');
    $cancel = app(CancelCommissionPeriod::class);

    expect($cancel(periodAdmin(), $started))->toBe(CancelCommissionPeriod::REFUSAL_ALREADY_STARTED)
        ->and($cancel(periodAdmin(), $future))->toBeNull()
        ->and(PropertyCommissionPeriod::query()->pluck('id')->all())->toBe([$started->id]);
});

test('the property commissions tab shows each service and the history', function () {
    $property = Property::factory()->create();
    startCommission($property->id, CommissionService::ChannelManager, '2026-09-01', '2026-09-30');
    startCommission($property->id, CommissionService::ChannelManager, '2026-11-01');

    $this->actingAs(periodAdmin())
        ->get(route('admin.properties.commissions', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/show')
            ->where('tab', 'commissions')
            ->where('commissionServices.0.service', 'booking_engine')
            ->where('commissionServices.0.rate', '10.00')
            ->where('commissionServices.0.period', null)
            ->where('commissionServices.1.period.startsOn', '2026-11-01')
            ->where('commissionServices.1.period.hasStarted', false)
            ->has('commissionHistory', 2)
            ->where('commissionHistory.1.endsOn', '2026-09-30'));
});

test('admins start, stop and cancel periods from the tab', function () {
    $property = Property::factory()->create();
    $admin = periodAdmin();

    $this->actingAs($admin)
        ->post(route('admin.properties.commissions.start', $property), ['service' => 'channel_manager', 'starts_on' => '2026-11-01'])
        ->assertRedirect(route('admin.properties.commissions', $property))
        ->assertInertiaFlash('toast.type', 'success');

    $period = PropertyCommissionPeriod::query()->sole();

    $this->put(route('admin.properties.commissions.stop', [$property, $period]), ['ends_on' => '2026-10-01'])
        ->assertInertiaFlash('toast.message', 'Choose today or a later date.');

    $this->delete(route('admin.properties.commissions.cancel', [$property, $period]))
        ->assertInertiaFlash('toast.type', 'success');

    expect(PropertyCommissionPeriod::query()->count())->toBe(0);
});

test('the tab is for admins, writes need two-factor, and a period of another property is not found', function () {
    $property = Property::factory()->create();
    $other = startCommission(Property::factory()->create()->id, CommissionService::BookingEngine, '2026-11-01');

    $this->actingAs(User::factory()->create())->get(route('admin.properties.commissions', $property))->assertNotFound();

    $this->actingAs(periodAdmin())
        ->delete(route('admin.properties.commissions.cancel', [$property, $other]))
        ->assertNotFound();

    config(['estatell.support_require_two_factor' => true]);
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.properties.commissions.start', $property), ['service' => 'booking_engine', 'starts_on' => '2026-11-01'])
        ->assertRedirect();

    expect(PropertyCommissionPeriod::query()->count())->toBe(1);
});
