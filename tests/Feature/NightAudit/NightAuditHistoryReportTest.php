<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\DailyStatistic;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Reports\NightAuditHistoryReport;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function auditHistoryUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/night-audit-history".$query;
}

/**
 * The audit of a closed business date.
 *
 * @param  'completed'|'failed'  $state
 * @param  array<string, mixed>  $attributes
 */
function auditOn(Property $property, string $date, string $state = 'completed', array $attributes = []): NightAudit
{
    $businessDate = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => $date]);

    return NightAudit::factory()->{$state}()->create(['business_date_id' => $businessDate->id, ...$attributes]);
}

test('the page needs reports.view, is scoped to the property and opens on the last 30 days, newest first', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();
    auditOn($own, '2026-09-11');
    auditOn($own, '2026-10-09');
    auditOn($own, '2026-09-10');
    auditOn($other, '2026-10-09');

    $this->actingAs($front)->get(auditHistoryUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(auditHistoryUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(auditHistoryUrl($own))->assertInertia(fn (Assert $page) => $page
        ->component('reports/night-audit-history')
        ->where('from', '2026-09-11')
        ->where('to', '2026-10-10')
        ->where('maxRangeDays', 366)
        ->where('sort', '-date')
        ->has('layout.columns', 17)
        ->where('rows.meta.total', 2)
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->all() === ['2026-10-09', '2026-09-11']));
});

test('a report that sets no default range still opens on today', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get("/p/{$property->code}/reports/arrivals")->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10'));
});

test('a row carries the status, who ran it, the force reason, the exceptions and the figures of the date', function () {
    [$user, $property] = reportSetup();
    $runner = User::factory()->create(['property_id' => $property->id, 'name' => 'Aina Runner']);
    $audit = auditOn($property, '2026-10-08', 'completed', ['run_by' => $runner->id, 'forced' => true, 'force_reason' => 'Power cut']);
    NightAuditException::factory()->create(['night_audit_id' => $audit->id]);
    NightAuditException::factory()->resolved()->create(['night_audit_id' => $audit->id]);
    DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-08', 'occupancy' => '62.50']);
    $room = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    $folio = Folio::factory()->create(['property_id' => $property->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $room->id, 'amount' => '300.00', 'unit_amount' => '300.00', 'posting_date' => '2026-10-08']);
    auditOn($property, '2026-10-07', 'failed');

    $this->actingAs($user)->get(auditHistoryUrl($property, '?from=2026-10-07&to=2026-10-08'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', function ($rows) {
            [$completed, $failed] = $rows;

            return $completed['status']['label'] === 'Completed'
                && $completed['run_by'] === 'Aina Runner'
                && $completed['forced'] === true
                && $completed['forceReason'] === 'Power cut'
                && $completed['exceptions'] === '1 / 2'
                && $completed['occupancy'] === '62.50'
                && $completed['revenue'] === '300.00'
                && $completed['packet'] === true
                && $failed['status']['label'] === 'Failed'
                && $failed['revenue'] === null
                && $failed['occupancy'] === null
                && $failed['packet'] === false;
        }));
});

test('the exports hold only the selected audits, with the force reason as text and an empty packet column', function () {
    [$user, $property] = reportSetup();
    auditOn($property, '2026-10-08', 'completed', ['forced' => true, 'force_reason' => 'Power cut']);
    auditOn($property, '2026-10-07');
    auditOn($property, '2026-10-06');

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/night-audit-history/spreadsheet", [
        'format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-10', 'rowKeys' => ['2026-10-08', '2026-10-06'],
    ]);

    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(4)
        ->and(array_column($rows, 0))->toBe(['Date', '2026-10-08', '2026-10-06', 'Total'])
        ->and($rows[0][10])->toBe('Packet')
        ->and($rows[1][6])->toBe('Yes: Power cut')
        ->and($rows[1][10])->toBe('')
        ->and($rows[2][6])->toBe('');

    $this->actingAs($user)->post("/p/{$property->code}/reports/night-audit-history/pdf", ['from' => '2026-10-01', 'to' => '2026-10-10'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('the split columns hold the exception counts and the dates and times in the property time zone, and the totals sum the counts', function () {
    [$user, $property] = reportSetup();
    $property->settings->forceFill(['time_zone' => 'Asia/Kuala_Lumpur'])->save();
    $audit = auditOn($property, '2026-10-08', 'completed', ['started_at' => '2026-10-08 16:30:00', 'completed_at' => '2026-10-08 16:45:00']);
    NightAuditException::factory()->count(2)->create(['night_audit_id' => $audit->id]);
    NightAuditException::factory()->resolved()->create(['night_audit_id' => $audit->id]);
    $second = auditOn($property, '2026-10-07');
    NightAuditException::factory()->create(['night_audit_id' => $second->id]);

    $rows = collect(app(NightAuditHistoryReport::class)->rows($property, new ReportFilters('2026-10-07', '2026-10-08')))->keyBy('key');

    expect($rows['2026-10-08']->cells())->toMatchArray([
        'exceptions' => '2 / 3', 'exceptions_open' => '2', 'exceptions_total' => '3',
        'started' => '9 Oct 2026 00:30', 'started_date' => '9 Oct 2026', 'started_time' => '00:30',
        'completed' => '9 Oct 2026 00:45', 'completed_date' => '9 Oct 2026', 'completed_time' => '00:45',
    ])
        ->and($rows['2026-10-08']->values())->toMatchArray(['exceptions_open' => 2, 'exceptions_total' => 3])
        ->and(ReportKey::NightAuditHistory->totals($rows->values()->all())->figures)->toMatchArray(['exceptions' => null, 'exceptions_open' => 3, 'exceptions_total' => 4, 'started_date' => null]);

    $defaults = collect(ReportKey::NightAuditHistory->columns())->mapWithKeys(fn ($column): array => [$column->key => $column->defaultVisible]);

    expect($defaults->only(['exceptions_open', 'exceptions_total', 'started_date', 'started_time', 'completed_date', 'completed_time'])->contains(true))->toBeFalse()
        ->and($defaults['exceptions'])->toBeTrue();

    $this->actingAs($user)->get(auditHistoryUrl($property, '?from=2026-10-07&to=2026-10-08'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.exceptions_open', 3)
        ->where('totals.figures.exceptions_total', 4));
});
