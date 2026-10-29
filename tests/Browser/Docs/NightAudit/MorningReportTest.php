<?php

use App\Actions\NightAudit\RunNightAudit;
use App\Enums\AuditTrigger;
use App\Notifications\NightAuditExceptions;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the morning report message', function () {
    $now = CarbonImmutable::parse('2026-10-14 12:00', 'UTC')->addHours(6);

    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);

    $result = app(RunNightAudit::class)($this->property, AuditTrigger::Manual, auth()->user());
    expect($result->isRefused())->toBeFalse();

    $notification = new NightAuditExceptions($result->audit);
    $html = (string) $notification->toMail(auth()->user())->render();

    Route::get('/docs-morning-report', fn () => response($html));

    $page = $this->open('/docs-morning-report', height: 1320)->wait(1);

    $this->shoot($page, 'morning-report-message');
});
