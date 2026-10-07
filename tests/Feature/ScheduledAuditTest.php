<?php

use App\Enums\AuditStatus;
use App\Enums\AuditTrigger;
use App\Enums\BusinessDateStatus;
use App\Jobs\RunNightAuditJob;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\DueAudits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

/**
 * A live property on its own time zone, trading on the given date with auto audit on and the audit at 02:00.
 *
 * @param  array<string, mixed>  $settings
 */
function scheduledProperty(string $date = '2026-10-12', array $settings = []): Property
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create([
        'time_zone' => 'Asia/Kuala_Lumpur',
        'audit_time' => '02:00',
        'auto_audit_enabled' => true,
        ...$settings,
    ]);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => $date]);

    return $property;
}

it('is due once local time reaches the audit time on the next day', function (): void {
    $property = scheduledProperty();

    // 2026-10-13 01:59 in Kuala Lumpur is 2026-10-12 17:59 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 17:59', 'UTC'));
    expect(app(DueAudits::class)->get())->toBeEmpty();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 18:00', 'UTC'));
    expect(app(DueAudits::class)->get()->pluck('id')->all())->toBe([$property->id]);
});

it('uses each property time zone', function (): void {
    $kualaLumpur = scheduledProperty();
    $london = scheduledProperty('2026-10-12', ['time_zone' => 'Europe/London']);

    // 2026-10-13 02:30 in Kuala Lumpur, still the evening of the 12th in London.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 18:30', 'UTC'));

    expect(app(DueAudits::class)->get()->pluck('id')->all())->toBe([$kualaLumpur->id]);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 02:30', 'UTC'));

    expect(app(DueAudits::class)->get()->pluck('id')->sort()->values()->all())->toBe([$kualaLumpur->id, $london->id]);
});

it('skips a property with auto audit off, not live, or already auditing', function (): void {
    scheduledProperty('2026-10-12', ['auto_audit_enabled' => false]);
    $notLive = scheduledProperty();
    $notLive->update(['status' => 'onboarding']);
    $auditing = scheduledProperty();
    $auditing->businessDates()->update(['status' => BusinessDateStatus::Auditing]);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 00:00', 'UTC'));

    expect(app(DueAudits::class)->get())->toBeEmpty();
});

it('queues a scheduled job that never forces', function (): void {
    Bus::fake();
    $property = scheduledProperty();
    scheduledProperty('2026-10-12', ['auto_audit_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 00:00', 'UTC'));

    $this->artisan('night-audit:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatchedTimes(RunNightAuditJob::class, 1);
    Bus::assertDispatched(fn (RunNightAuditJob $job): bool => $job->property->is($property)
        && $job->trigger === AuditTrigger::Scheduled
        && $job->actor === null
        && $job->force === false);
});

it('closes the date and opens the next one when the schedule runs', function (): void {
    $property = scheduledProperty();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 00:00', 'UTC'));

    $this->artisan('night-audit:dispatch-scheduled')->assertSuccessful();

    expect(NightAudit::query()->where('property_id', $property->id)->first())
        ->trigger->toBe(AuditTrigger::Scheduled)
        ->status->toBe(AuditStatus::Completed)
        ->and($property->businessDates()->current()->first()->date->toDateString())->toBe('2026-10-13');

    // The next date is not due until the day after.
    expect(app(DueAudits::class)->get())->toBeEmpty();
});

it('stops a scheduled run at a blocker and leaves the date open', function (): void {
    $property = scheduledProperty();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 00:00', 'UTC'));
    $this->mock(AuditReadiness::class, fn ($mock) => $mock->shouldReceive('gate')->andReturn('blocked_by_readiness'));

    $this->artisan('night-audit:dispatch-scheduled')->assertSuccessful();

    expect(NightAudit::query()->where('property_id', $property->id)->exists())->toBeFalse()
        ->and($property->businessDates()->current()->first()->status)->toBe(BusinessDateStatus::Open);
});

it('is on the schedule every five minutes', function (): void {
    $event = collect(Schedule::events())->first(fn ($event): bool => str_contains($event->command, 'night-audit:dispatch-scheduled'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
});
