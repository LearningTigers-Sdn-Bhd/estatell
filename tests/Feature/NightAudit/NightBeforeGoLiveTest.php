<?php

require_once __DIR__.'/../Support/PastStaySetup.php';

use App\Actions\Folio\PostingResult;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\NightAudit\PostMissedNights;
use App\Actions\NightAudit\PostRoomCharges;
use App\Actions\NightAudit\RecordAuditException;
use App\Actions\NightAudit\RetryRoomCharge;
use App\Enums\AuditExceptionType;
use App\Enums\UnitStatus;
use App\Jobs\PostRoomChargesJob;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Services\NightAudit\FirstBusinessDate;
use Carbon\CarbonImmutable;

/**
 * A CheckedIn unit that arrived on 2026-10-08, two days before the property went live on 2026-10-10.
 *
 * @param  array<string, mixed>  $setup
 */
function goLiveUnit(array $setup)
{
    return pastStayUnit($setup, '2026-10-08', '2026-10-16', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => '2026-10-08 15:00:00']);
}

test('a night before go-live is refused and writes nothing, and a night from go-live is not', function () {
    $setup = pastStaySetup();
    $unit = goLiveUnit($setup);
    $post = app(PostRoomCharges::class);

    $early = $post(null, $unit, CarbonImmutable::parse('2026-10-09'));
    $live = $post(null, $unit, CarbonImmutable::parse('2026-10-10'));

    expect($early->refusal)->toBe(PostingResult::REFUSAL_NIGHT_BEFORE_GO_LIVE)
        ->and($live->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('idempotency_key', 'night:'.$unit->nights()->whereDate('date', '2026-10-09')->value('id'))->exists())->toBeFalse()
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->count())->toBe(1);
});

test('the audit job turns a night before go-live into an exception and charges nothing', function () {
    $setup = pastStaySetup();
    $unit = goLiveUnit($setup);
    $audit = NightAudit::factory()->create(['business_date_id' => BusinessDate::query()->where('property_id', $setup['property']->id)->orderBy('date')->firstOrFail()->id]);

    (new PostRoomChargesJob($audit->id, $unit->id, '2026-10-09'))->handle(app(PostRoomCharges::class), app(RecordAuditException::class));

    $exception = NightAuditException::query()->where('night_audit_id', $audit->id)->sole();
    expect($exception->type)->toBe(AuditExceptionType::RoomCharge)
        ->and($exception->message)->toBe('night_before_go_live')
        ->and(FolioLine::query()->count())->toBe(0);
});

test('Retry refuses a night before go-live and leaves the exception open', function () {
    $setup = pastStaySetup();
    $unit = goLiveUnit($setup);
    $audit = NightAudit::factory()->completed()->create(['business_date_id' => BusinessDate::query()->where('property_id', $setup['property']->id)->whereDate('date', '2026-10-10')->firstOrFail()->id]);
    $exception = NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $unit->id, 'message' => 'folio_not_open']);
    app()->instance(FirstBusinessDate::class, new class extends FirstBusinessDate
    {
        public function date(Property $property): ?CarbonImmutable
        {
            return CarbonImmutable::parse('2026-10-11');
        }
    });

    $result = app(RetryRoomCharge::class)($setup['user'], $exception);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_NIGHT_BEFORE_GO_LIVE)
        ->and($exception->refresh()->resolved_at)->toBeNull()
        ->and(FolioLine::query()->count())->toBe(0);
});

test('missed nights start at go-live, whatever the arrival', function () {
    $setup = pastStaySetup();
    $unit = goLiveUnit($setup);

    $result = app(PostMissedNights::class)($setup['user'], $unit);

    $dates = FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->orderBy('service_date')->get()->map(fn ($line): string => $line->service_date->toDateString())->all();
    expect($result->isRefused())->toBeFalse()
        ->and($dates)->toBe(['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13']);
});

test('a late check-in catches up the nights from go-live only', function () {
    $setup = pastStaySetup();
    $unit = pastStayUnit($setup, '2026-10-08', '2026-10-16');

    $result = app(CheckInUnit::class)($setup['user'], $unit, true);

    $dates = FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->orderBy('service_date')->get()->map(fn ($line): string => $line->service_date->toDateString())->all();
    expect($result->isRefused())->toBeFalse()
        ->and($dates)->toBe(['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13']);
});
