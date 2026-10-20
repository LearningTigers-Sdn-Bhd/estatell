<?php

use App\Actions\NightAudit\OpenBusinessDate;
use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

test('the current business date is the open one', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->for($property)->closed()->create(['date' => '2026-10-08']);
    $open = BusinessDate::factory()->for($property)->create(['date' => '2026-10-09']);

    $service = app(CurrentBusinessDate::class);

    expect($service->get($property)->is($open))->toBeTrue()
        ->and($service->date($property))->toBeInstanceOf(CarbonImmutable::class)
        ->and($service->date($property)->toDateString())->toBe('2026-10-09');
});

test('the current business date is still the one being audited', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->for($property)->auditing()->create(['date' => '2026-10-09']);

    expect(app(CurrentBusinessDate::class)->get($property)->status)->toBe(BusinessDateStatus::Auditing);
});

test('a property with no open date, or only closed dates, has no current business date', function () {
    $property = Property::factory()->live()->create();

    expect(app(CurrentBusinessDate::class)->get($property))->toBeNull()
        ->and(app(CurrentBusinessDate::class)->date($property))->toBeNull();

    BusinessDate::factory()->for($property)->closed()->create(['date' => '2026-10-09']);

    expect(app(CurrentBusinessDate::class)->get($property))->toBeNull();
});

test('the current business date never comes from another property', function () {
    BusinessDate::factory()->create(['date' => '2026-10-09']);

    expect(app(CurrentBusinessDate::class)->get(Property::factory()->live()->create()))->toBeNull();
});

test('a property cannot have two current business dates', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-09']);

    expect(fn () => BusinessDate::factory()->for($property)->auditing()->create(['date' => '2026-10-10']))
        ->toThrow(QueryException::class);
});

test('a property can have many closed dates but each date only once', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->for($property)->closed()->create(['date' => '2026-10-08']);
    BusinessDate::factory()->for($property)->closed()->create(['date' => '2026-10-09']);

    expect(fn () => BusinessDate::factory()->for($property)->create(['date' => '2026-10-09']))
        ->toThrow(QueryException::class);
});

test('opening the first business date', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $date = app(OpenBusinessDate::class)($property, CarbonImmutable::parse('2026-10-10'), $owner);

    expect($date->property_id)->toBe($property->id)
        ->and($date->date->toDateString())->toBe('2026-10-10')
        ->and($date->status)->toBe(BusinessDateStatus::Open)
        ->and($date->closed_at)->toBeNull()
        ->and(Activity::query()->where('event', 'business-date.opened')->where('causer_id', $owner->id)->count())->toBe(1);
});

test('a second business date is refused and logs nothing', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->for($property)->closed()->create(['date' => '2026-10-09']);

    expect(app(OpenBusinessDate::class)($property, CarbonImmutable::parse('2026-10-10')))->toBeNull()
        ->and($property->businessDates()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'business-date.opened')->count())->toBe(0);
});

test('staff read the current business date as JSON', function () {
    $staff = User::factory()->create();
    $property = $staff->property;
    PropertySetting::factory()->for($property)->create(['time_zone' => 'Asia/Kuala_Lumpur', 'audit_time' => '02:00']);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-09']);

    $this->actingAs($staff)
        ->getJson(route('business-date.show', $property->code))
        ->assertOk()
        ->assertExactJson([
            'date' => '2026-10-09',
            'status' => 'open',
            'timeZone' => 'Asia/Kuala_Lumpur',
            'auditTime' => '02:00',
            'serverNow' => now()->toIso8601String(),
        ]);
});

test('the JSON is null while the property is not live', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->getJson(route('business-date.show', $staff->property->code))
        ->assertOk()
        ->assertContent('null');
});

test('staff get a 404 on the business date of another property', function () {
    $other = BusinessDate::factory()->create()->property;

    $this->actingAs(User::factory()->create())
        ->getJson(route('business-date.show', $other->code))
        ->assertNotFound();
});

test('guests are sent to log in', function () {
    $property = Property::factory()->live()->create();

    $this->getJson(route('business-date.show', $property->code))->assertUnauthorized();
});

test('the business date is a shared prop on every property page', function () {
    $staff = User::factory()->create();
    $property = $staff->property;
    BusinessDate::factory()->for($property)->auditing()->create(['date' => '2026-10-09']);

    $this->actingAs($staff)
        ->get(route('dashboard', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('businessDate.date', '2026-10-09')
            ->where('businessDate.status', 'auditing')
            ->where('businessDate.timeZone', config('app.timezone'))
            ->where('businessDate.auditTime', '02:00')
            ->has('businessDate.serverNow'));
});

test('the shared business date is null before go live and for admins', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('dashboard', $staff->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('businessDate', null));

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('businessDate', null));
});
