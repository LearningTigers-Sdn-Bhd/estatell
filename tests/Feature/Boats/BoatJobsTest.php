<?php

use App\Actions\Boats\DeleteBoatJob;
use App\Actions\Boats\SaveBoatJob;
use App\Actions\Boats\SetBoatJobDone;
use App\Data\Boats\SailingData;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatJobType;
use App\Enums\SailingKind;
use App\Models\BoatSailing;
use App\Models\User;
use App\Services\Reports\BoatManifestReport;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/BoatSailingHelpers.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/** A Maintenance job at 16:00 on 20 Oct with MV Sand Bay 2, Razak in charge and Din. */
function boatJob(array $setup, array $overrides = []): BoatSailing
{
    $result = app(SaveBoatJob::class)(
        $setup['owner'],
        $setup['property'],
        $setup['date'],
        $overrides['type'] ?? BoatJobType::Maintenance,
        $overrides['leaves_at'] ?? '16:00',
        $overrides['note'] ?? 'Engine service',
        new SailingData(boatId: $setup['spare']->id, inChargeId: $setup['razak']->id, assistantIds: [$setup['din']->id]),
    );
    expect($result->isRefused())->toBeFalse();

    return $result->sailing->refresh();
}

function jobBody(array $setup, array $overrides = []): array
{
    return [
        'date' => '2026-10-20',
        'job_type' => 'diesel',
        'leaves_at' => '11:00',
        'boat_id' => $setup['spare']->id,
        'in_charge_id' => $setup['razak']->id,
        'assistant_ids' => [],
        'note' => '4 drums',
        ...$overrides,
    ];
}

test('a boat job is a sailing of kind Job with its type, time, boat, crew and note, and no direction or arrive time', function () {
    $setup = sailingSetup();

    $job = boatJob($setup);

    expect($job->kind)->toBe(SailingKind::Job)
        ->and($job->job_type)->toBe(BoatJobType::Maintenance)
        ->and([$job->leaves_at, $job->arrives_at, $job->direction, $job->boat_departure_id])->toBe(['16:00:00', null, null, null])
        ->and($job->boat_id)->toBe($setup['spare']->id)
        ->and($job->note)->toBe('Engine service')
        ->and($job->crew->pluck('name')->all())->toBe(['Razak', 'Din'])
        ->and(Activity::query()->where('event', 'boat_job.saved')->count())->toBe(1);
});

test('staff add, edit and delete a job on the board, and Other needs a note', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['owner'])->post(route('boats.jobs.store', $code), jobBody($setup))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat job added.']);
    $job = BoatSailing::query()->sole();

    $this->post(route('boats.jobs.store', $code), jobBody($setup, ['job_type' => 'other', 'note' => '']))
        ->assertSessionHasErrors(['note' => 'Say what the job is.']);

    $this->put(route('boats.jobs.update', [$code, $job->id]), jobBody($setup, ['job_type' => 'maintenance', 'leaves_at' => '15:30', 'note' => '']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat job saved.']);
    expect([$job->refresh()->job_type, $job->leaves_at, $job->note])->toBe([BoatJobType::Maintenance, '15:30:00', null]);

    $this->delete(route('boats.jobs.destroy', [$code, $job->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat job deleted.']);
    expect(BoatSailing::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'boat_job.deleted')->count())->toBe(1);
});

test('Done records who and when, a repeat changes nothing, and a done job cannot be changed or deleted until unticked', function () {
    $setup = sailingSetup();
    $job = boatJob($setup);
    $code = $setup['property']->code;

    $this->actingAs($setup['owner'])->put(route('boats.jobs.done', [$code, $job->id]), ['done' => true])->assertRedirect();
    $this->put(route('boats.jobs.done', [$code, $job->id]), ['done' => true])->assertRedirect();
    expect($job->refresh()->done_at)->not->toBeNull()
        ->and($job->done_by)->toBe($setup['owner']->id)
        ->and(Activity::query()->where('event', 'boat_job.done')->count())->toBe(1);

    $this->put(route('boats.jobs.update', [$code, $job->id]), jobBody($setup))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This job is done. Untick Done first.']);
    $this->delete(route('boats.jobs.destroy', [$code, $job->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This job is done. Untick Done first.']);
    expect($job->refresh()->job_type)->toBe(BoatJobType::Maintenance)
        ->and(Activity::query()->where('event', 'boat_job.saved')->count())->toBe(1);

    app(SetBoatJobDone::class)($setup['owner'], $job, false);
    expect($job->refresh()->done_at)->toBeNull()
        ->and($job->done_by)->toBeNull()
        ->and(Activity::query()->where('event', 'boat_job.undone')->count())->toBe(1)
        ->and(app(DeleteBoatJob::class)($setup['owner'], $job)->isRefused())->toBeFalse();
});

test('a past date is read only for jobs except Done, and an unavailable boat is refused', function () {
    $setup = sailingSetup();
    $job = boatJob($setup);
    $job->update(['date' => '2026-10-09']);
    $past = CarbonImmutable::parse('2026-10-09');

    $save = app(SaveBoatJob::class);
    $crew = new SailingData(boatId: $setup['spare']->id);

    expect($save($setup['owner'], $setup['property'], $past, BoatJobType::Diesel, '09:00', null, $crew)->refusal)->toBe('date_closed')
        ->and($save($setup['owner'], $setup['property'], $setup['date'], BoatJobType::Diesel, '09:00', null, $crew, $job)->refusal)->toBe('date_closed')
        ->and(app(DeleteBoatJob::class)($setup['owner'], $job)->refusal)->toBe('date_closed')
        ->and(app(SetBoatJobDone::class)($setup['owner'], $job, true)->isRefused())->toBeFalse();

    $setup['spare']->update(['active' => false]);
    expect($save($setup['owner'], $setup['property'], $setup['date'], BoatJobType::Diesel, '09:00', null, $crew)->refusal)->toBe('boat_unavailable')
        ->and(BoatSailing::query()->count())->toBe(1);
});

test('the board shows Boat jobs after Own boat on All and Others, not on Boat in, and counts the job crew as busy', function () {
    $setup = sailingSetup();
    $job = boatJob($setup);
    $url = fn (array $query = []) => route('boats.index', ['property' => $setup['property']->code, 'date' => '2026-10-20', ...$query]);

    $this->actingAs($setup['owner'])->get($url())
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.2.kind', 'charter')
            ->where('groups.3.kind', 'own')
            ->where('groups.4.kind', 'jobs')
            ->where('groups.4.jobs.0.id', $job->id)
            ->where('groups.4.jobs.0.jobLabel', 'Maintenance')
            ->where('groups.4.jobs.0.boat', 'MV Sand Bay 2')
            ->where('groups.4.jobs.0.inCharge', 'Razak')
            ->where('groups.4.jobs.0.done', false)
            ->where('jobTypes.4', ['value' => 'maintenance', 'label' => 'Maintenance'])
            ->where('sailingCrews.2', ['key' => 'job-'.$job->id, 'time' => '16:00', 'boatId' => $setup['spare']->id, 'crewIds' => [$setup['razak']->id, $setup['din']->id]]));

    $this->get($url(['direction' => 'other']))
        ->assertInertia(fn (Assert $page) => $page->has('groups', 3)->where('groups.2.kind', 'jobs'));

    $this->get($url(['direction' => 'in']))
        ->assertInertia(fn (Assert $page) => $page->where('groups', fn ($groups) => collect($groups)->doesntContain('kind', 'jobs')));
});

test('the manifest prints a Boat jobs block and the spreadsheet adds job rows, but not on Boat in or for ticked rows', function () {
    $setup = sailingSetup();
    boatJob($setup);
    $report = app(BoatManifestReport::class);
    $all = new ReportFilters(from: '2026-10-20', to: '2026-10-20', bothDirections: true);
    $in = new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: null, boatGroup: 'scheduled');

    $block = collect($report->printData($setup['property'], $all, [])['sections'])->firstWhere('kind', 'job');
    $rows = $report->jobRows($setup['property'], $all);

    expect($block['jobs'][0]->jobLabel)->toBe('Maintenance')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->cells())->toMatchArray(['departure' => 'Boat job · 16:00', 'lead_guest' => 'Maintenance', 'boat' => 'MV Sand Bay 2', 'crew' => 'Razak (in charge), Din', 'note' => 'Engine service', 'tick' => ''])
        ->and(collect($report->printData($setup['property'], $in, [])['sections'])->firstWhere('kind', 'job'))->toBeNull()
        ->and($report->jobRows($setup['property'], $in))->toBe([])
        ->and($report->jobRows($setup['property'], $all, [1]))->toBe([]);

    $this->actingAs($setup['owner'])->post(route('boats.pdf', $setup['property']->code), ['date' => '2026-10-20', 'direction' => 'both'])->assertOk();
    $this->post(route('boats.spreadsheet', $setup['property']->code), ['date' => '2026-10-20', 'direction' => 'both', 'format' => 'csv'])->assertOk();
});

test('job routes need reservations.manage, and a job of another property or a guest sailing is a 404', function () {
    $setup = sailingSetup();
    $job = boatJob($setup);
    $code = $setup['property']->code;
    $viewer = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();

    $this->actingAs($viewer)->post(route('boats.jobs.store', $code), jobBody($setup))->assertForbidden();
    $this->put(route('boats.jobs.done', [$code, $job->id]), ['done' => true])->assertForbidden();
    $this->delete(route('boats.jobs.destroy', [$code, $job->id]))->assertForbidden();

    $foreign = sailingSetup();
    $foreignJob = boatJob($foreign);
    $guestSailing = BoatSailing::factory()->forDeparture($setup['departure'])->create();

    $this->actingAs($setup['owner'])->put(route('boats.jobs.update', [$code, $foreignJob->id]), jobBody($setup))->assertNotFound();
    $this->put(route('boats.jobs.done', [$code, $foreignJob->id]), ['done' => true])->assertNotFound();
    $this->delete(route('boats.jobs.destroy', [$code, $guestSailing->id]))->assertNotFound();
    expect($foreignJob->refresh()->done_at)->toBeNull();
});
