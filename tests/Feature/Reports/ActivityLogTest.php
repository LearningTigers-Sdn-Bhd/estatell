<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Guest;
use App\Models\User;
use App\Navigation\ReportsNavigation;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('the activity log requires authentication, reports permission and property access', function () {
    [$front, $property] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $other] = reportSetup();

    $this->get(route('reports.activity-log', $property))->assertRedirect(route('login'));
    $this->actingAs($front)->get(route('reports.activity-log', $property))->assertForbidden();
    $this->actingAs($manager)->get(route('reports.activity-log', $property))->assertNotFound();

    expect(collect(app(ReportsNavigation::class)->groups($other, $front)))->toBeEmpty();
});

test('the activity log shows only explicitly scoped property entries and safe fields', function () {
    [, $property] = reportSetup();
    [, $other] = reportSetup();
    $user = User::factory()->for($property)->withPermissions([PermissionEnum::ReportsView])->create(['name' => 'Aina']);
    $guest = Guest::factory()->for($property)->create();

    $entry = activity()->performedOn($guest)->causedBy($user)->event('guest.updated')
        ->withProperties(['property_id' => $property->id, 'token' => 'secret-token', 'number' => 'secret-id'])
        ->log('Guest updated');
    $direct = activity()->performedOn($property)->causedBy($user)->event('property.updated')->log('Property updated');
    activity()->performedOn($other)->causedBy($user)->log('Other property');
    activity()->causedBy($user)->withProperties(['property_id' => $other->id])->log('Other property tagged');
    activity()->causedBy($user)->log('Unscoped activity by the same staff member');

    $this->actingAs($user)->get(route('reports.activity-log', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/activity-log')
            ->where('sort', '-created_at')
            ->where('search', '')
            ->has('entries.data', 2)
            ->where('entries.data.0.id', $direct->id)
            ->where('entries.data.1.id', $entry->id)
            ->where('entries.data.1.description', 'Guest updated')
            ->where('entries.data.1.event', 'guest.updated')
            ->where('entries.data.1.subject', 'Guest #'.$guest->id)
            ->where('entries.data.1.causerName', 'Aina')
            ->missing('entries.data.1.properties')
            ->missing('entries.data.1.causer')
            ->missing('entries.data.1.subject_type'))
        ->assertDontSee('secret-token')
        ->assertDontSee('secret-id');

    $audit = collect(app(ReportsNavigation::class)->groups($property, $user))->firstWhere('label', 'Audit');
    $item = collect($audit->items)->firstWhere('title', 'Activity log');

    expect($item->href)->toBe('/p/'.$property->code.'/reports/activity-log')
        ->and($item->active)->toBeTrue();
});

test('search matches action descriptions or event keys without crossing properties', function (string $search) {
    [$user, $property] = reportSetup();
    [, $other] = reportSetup();
    $entry = activity()->withProperties(['property_id' => $property->id])->event('guest.updated')->log('Guest updated');
    activity()->withProperties(['property_id' => $property->id])->log('Room created');
    activity()->withProperties(['property_id' => $other->id])->event('guest.updated')->log('Guest updated');
    activity()->performedOn($other)->event('guest.updated')->log('Guest updated');

    $this->actingAs($user)->get(route('reports.activity-log', ['property' => $property, 'filter' => ['search' => $search]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('search', $search)
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $entry->id));
})->with(['description' => 'GUEST UPDATED', 'event' => 'guest.updated']);

test('the activity log paginates in stable newest-first order and keeps search in page links', function () {
    [$user, $property] = reportSetup();
    $ids = [];

    foreach (range(1, 26) as $number) {
        $ids[] = activity()->withProperties(['property_id' => $property->id])->log('Guest updated')->id;
    }

    $url = route('reports.activity-log', ['property' => $property, 'filter' => ['search' => 'Guest']]);
    $firstPage = $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 25)
        ->where('entries.meta.total', 26)
        ->where('entries.data.0.id', $ids[25]));

    $next = $firstPage->inertiaProps('entries.meta.next_page_url');
    expect(urldecode($next))->toContain('filter[search]=Guest');

    $this->get($next)->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 1)
        ->where('entries.data.0.id', $ids[0]));
});

test('time sorting orders by the recorded timestamp rather than the id', function () {
    [$user, $property] = reportSetup();
    $newer = activity()->withProperties(['property_id' => $property->id])->log('Newer');
    $older = activity()->withProperties(['property_id' => $property->id])->log('Older');
    $older->forceFill(['created_at' => now()->subDay()])->save();

    $this->actingAs($user)->get(route('reports.activity-log', ['property' => $property, 'sort' => 'created_at']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', 'created_at')
            ->where('entries.data.0.id', $older->id)
            ->where('entries.data.1.id', $newer->id));
});

test('system and missing user entries remain readable without loading the subject', function () {
    [$user, $property] = reportSetup();
    $system = activity()->withProperties(['property_id' => $property->id])->log('Automatic action');
    $missing = new Activity;
    $missing->forceFill([
        'description' => 'Historical action',
        'properties' => ['property_id' => $property->id],
        'causer_type' => (new User)->getMorphClass(),
        'causer_id' => 999999,
        'subject_type' => (new Guest)->getMorphClass(),
        'subject_id' => 999999,
    ])->save();

    $this->actingAs($user)->get(route('reports.activity-log', $property))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.causerName', 'Unknown user')
        ->where('entries.data.0.subject', 'Guest #999999')
        ->where('entries.data.1.id', $system->id)
        ->where('entries.data.1.causerName', 'System')
        ->where('entries.data.1.subject', null));
});

test('invalid activity log filters are rejected', function (array $query, string $field) {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get(route('reports.activity-log', ['property' => $property, ...$query]))
        ->assertSessionHasErrors($field);
})->with([
    'array search' => [['filter' => ['search' => ['invalid']]], 'filter.search'],
    'long search' => [['filter' => ['search' => str_repeat('a', 201)]], 'filter.search'],
    'unapproved filter' => [['filter' => ['property_id' => 123]], 'filter'],
    'unapproved sort' => [['sort' => 'properties'], 'sort'],
    'invalid page' => [['page' => 0], 'page'],
]);

test('the activity log has an empty state when no records exist', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get(route('reports.activity-log', $property))->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 0)
        ->where('entries.meta.total', 0));
});
