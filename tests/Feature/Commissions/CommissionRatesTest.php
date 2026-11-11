<?php

use App\Enums\CommissionService;
use App\Models\CommissionRate;
use App\Models\User;
use App\Services\Commissions\CommissionRates;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('the migration seeds booking engine 10% and channel manager 3%', function () {
    $rates = app(CommissionRates::class);

    expect($rates->rateOn(CommissionService::BookingEngine, CarbonImmutable::parse('2026-10-14')))->toBe('10.00')
        ->and($rates->rateOn(CommissionService::ChannelManager, CarbonImmutable::parse('2026-10-14')))->toBe('3.00')
        ->and($rates->rateOn(CommissionService::BookingEngine, CarbonImmutable::parse('2025-12-31')))->toBeNull();
});

test('a booking uses the rate in force on the day it was made', function () {
    CommissionRate::factory()->create(['service' => CommissionService::BookingEngine, 'rate' => '8.00', 'effective_from' => '2026-12-01']);
    $rates = app(CommissionRates::class);

    expect($rates->rateOn(CommissionService::BookingEngine, CarbonImmutable::parse('2026-11-30')))->toBe('10.00')
        ->and($rates->rateOn(CommissionService::BookingEngine, CarbonImmutable::parse('2026-12-01')))->toBe('8.00')
        ->and($rates->rateOn(CommissionService::ChannelManager, CarbonImmutable::parse('2026-12-01')))->toBe('3.00');
});

test('the commissions tab lists rates as scheduled, current and past', function () {
    CommissionRate::factory()->create(['service' => CommissionService::BookingEngine, 'rate' => '8.00', 'effective_from' => '2026-12-01']);
    CommissionRate::factory()->create(['service' => CommissionService::BookingEngine, 'rate' => '12.00', 'effective_from' => '2026-06-01']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.settings.commissions'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/edit')
            ->where('activeTab', 'commissions')
            ->missing('settings.requireGoLiveApproval')
            ->where('commissionServices.0.value', 'booking_engine')
            ->where('commissionRates.0.rate', '8.00')
            ->where('commissionRates.0.status.value', 'scheduled')
            ->where('commissionRates.1.rate', '12.00')
            ->where('commissionRates.1.status.value', 'current')
            ->where('commissionRates.2.rate', '10.00')
            ->where('commissionRates.2.status.value', 'past')
            ->where('commissionRates.3.service', 'channel_manager')
            ->where('commissionRates.3.status.value', 'current')
        );
});

test('an admin adds a rate from a future date and it is logged', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('admin.settings.commissions.rates.store'), [
            'service' => 'channel_manager',
            'rate' => '2.5',
            'effective_from' => '2026-12-01',
        ])
        ->assertRedirect(route('admin.settings.commissions'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Commission rate saved.']);

    $rate = CommissionRate::query()->where('effective_from', '2026-12-01')->sole();
    expect($rate->service)->toBe(CommissionService::ChannelManager)
        ->and($rate->rate)->toBe('2.50')
        ->and($rate->created_by)->toBe($admin->id)
        ->and(Activity::query()->sole()->description)->toBe('Commission rate changed');
});

test('a rate is refused for a past date, a taken date or a value over 100', function (array $payload, string $field) {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.settings.commissions.rates.store'), [
            'service' => 'booking_engine',
            'rate' => '9',
            'effective_from' => '2026-12-01',
            ...$payload,
        ])
        ->assertSessionHasErrors($field);

    expect(CommissionRate::query()->count())->toBe(2)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'past date' => [['effective_from' => '2026-10-13'], 'effective_from'],
    'taken date' => [['effective_from' => '2026-01-01'], 'effective_from'],
    'over 100' => [['rate' => '100.01'], 'rate'],
    'three decimals' => [['rate' => '9.125'], 'rate'],
]);

test('rates are admin only and need two-factor to save', function () {
    $payload = ['service' => 'booking_engine', 'rate' => '9', 'effective_from' => '2026-12-01'];

    $this->actingAs(User::factory()->create())
        ->post(route('admin.settings.commissions.rates.store'), $payload)
        ->assertNotFound();

    config(['estatell.support_require_two_factor' => true]);
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.settings.commissions.rates.store'), $payload)
        ->assertRedirect();

    expect(CommissionRate::query()->count())->toBe(2);
});
