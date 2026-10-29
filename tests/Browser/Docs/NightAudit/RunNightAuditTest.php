<?php

use App\Enums\AuditStatus;
use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\NightAuditStep;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Move the clock to the given hours after the frozen 14 Oct 2026, 20:00 in Kuala Lumpur, so the business date has ended.
 */
$moveClockBy = function (int $hours) {
    $now = CarbonImmutable::parse('2026-10-14 12:00', 'UTC')->addHours($hours);

    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);
};

/**
 * Start the audit of the current business date by hand: the date is Auditing and its audit has these steps.
 *
 * @param  array<string, AuditStepStatus>  $steps  Step value => status; the steps left out have not started.
 */
$startAudit = function (AuditStatus $status, array $steps) {
    $date = BusinessDate::query()->where('property_id', $this->property->id)->current()->firstOrFail();
    $date->update(['status' => BusinessDateStatus::Auditing]);

    $audit = NightAudit::factory()->create(['property_id' => $this->property->id, 'business_date_id' => $date->id, 'status' => $status]);

    foreach ($steps as $step => $stepStatus) {
        NightAuditStep::factory()->create([
            'night_audit_id' => $audit->id,
            'step' => $step,
            'status' => $stepStatus,
            'processed_count' => $stepStatus === AuditStepStatus::Completed ? 2 : 0,
            'error_message' => $stepStatus === AuditStepStatus::Failed ? 'Connection timed out' : null,
        ]);
    }

    return $audit;
};

test('the sheet before the day ends', function () {
    $page = $this->open(route('dashboard', $this->property))
        ->click('Run Night Audit')
        ->waitForText('Not over yet')
        ->assertSee('If the automatic run is on, it starts after the night audit time.');

    $this->shoot($page, 'run-night-audit-not-over', frame: '[role="dialog"]');
});

test('the sheet ready to run, then completed', function () use ($moveClockBy) {
    $moveClockBy->call($this, 5);

    $page = $this->open(route('dashboard', $this->property))
        ->click('Run Night Audit')
        ->waitForText('Ready to run');

    $this->shoot($page, 'run-night-audit-ready', frame: '[role="dialog"]');

    $page->click('Run audit')
        ->waitForText('Night audit completed', 15)
        ->assertSee('Closed Wed 14 Oct · now trading on Thu 15 Oct');

    $this->shoot($page, 'run-night-audit-completed', badges: ['Open packet'], frame: '[role="dialog"]');
});

test('the sheet while the audit runs', function () use ($startAudit) {
    $startAudit->call($this, AuditStatus::Running, [
        AuditStep::ResolveMissedArrivals->value => AuditStepStatus::Completed,
        AuditStep::ResolveOverdueDepartures->value => AuditStepStatus::Completed,
        AuditStep::PostRoomCharges->value => AuditStepStatus::Running,
    ]);

    $page = $this->open(route('dashboard', $this->property))
        ->click('Run Night Audit')
        ->waitForText('Running the audit');

    $this->shoot($page, 'run-night-audit-running', frame: '[role="dialog"]');
});

test('the sheet after a failed audit', function () use ($startAudit) {
    $startAudit->call($this, AuditStatus::Failed, [
        AuditStep::ResolveMissedArrivals->value => AuditStepStatus::Completed,
        AuditStep::ResolveOverdueDepartures->value => AuditStepStatus::Completed,
        AuditStep::PostRoomCharges->value => AuditStepStatus::Completed,
        AuditStep::PostScheduledAddOns->value => AuditStepStatus::Failed,
    ]);

    $page = $this->open(route('dashboard', $this->property))
        ->click('Run Night Audit')
        ->waitForText('The audit stopped at');

    $this->shoot($page, 'run-night-audit-failed', badges: ['Resume audit'], frame: '[role="dialog"]');
});
