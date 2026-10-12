<?php

use App\Actions\NightAudit\DismissAuditException;
use App\Enums\AuditExceptionType;
use App\Enums\PermissionEnum;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/** @return array{property: Property, user: User, unit: ReservationUnit, exception: NightAuditException} */
function dismissSetup(bool $canRun = true): array
{
    test()->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    $date = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-13']);
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $audit = NightAudit::factory()->completed()->create(['business_date_id' => $date->id]);
    $exception = NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $unit->id]);
    $user = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Audit']);
    if ($canRun) {
        $role->givePermissionTo(PermissionEnum::NightAuditRun->value);
    }
    setPermissionsTeamId($property->id);
    $user->assignRole($role);

    return compact('property', 'user', 'unit', 'exception');
}

test('Dismiss resolves any carried exception with its actor and reason and posts nothing', function (AuditExceptionType $type) {
    $setup = dismissSetup();
    $setup['exception']->forceFill(['type' => $type])->save();
    $before = $setup['unit']->nights()->orderBy('date')->get()->toArray();

    $this->actingAs($setup['user'])->postJson(route('night-audit.exceptions.dismiss', [$setup['property']->code, $setup['exception']]), ['reason' => '  Charged by hand.  '])->assertOk()->assertJsonPath('done', 1);

    $exception = $setup['exception']->refresh();
    $activity = Activity::query()->where('event', 'night_audit.exception_dismissed')->sole();
    expect($exception->resolved_at)->not->toBeNull()
        ->and($exception->resolved_by)->toBe($setup['user']->id)
        ->and($activity->subject_id)->toBe($setup['unit']->reservation_id)
        ->and($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->properties['reason'])->toBe('Charged by hand.')
        ->and($setup['unit']->nights()->orderBy('date')->get()->toArray())->toBe($before)
        ->and(FolioLine::query()->count())->toBe(0);
    $warnings = $this->getJson(route('night-audit.show', $setup['property']->code))->json('warnings');
    expect(array_column($warnings, 'check'))->not->toContain('carried_exception');
})->with(AuditExceptionType::cases());

test('Dismiss resolves an exception with no unit and logs on the exception itself', function () {
    $setup = dismissSetup();
    $setup['exception']->forceFill(['reservation_unit_id' => null])->save();

    $this->actingAs($setup['user'])->postJson(route('night-audit.exceptions.dismiss', [$setup['property']->code, $setup['exception']]), ['reason' => 'Handled separately.'])->assertOk()->assertJsonPath('done', 1);

    $exception = $setup['exception']->refresh();
    $activity = Activity::query()->where('event', 'night_audit.exception_dismissed')->sole();
    expect($exception->resolved_at)->not->toBeNull()
        ->and($exception->resolved_by)->toBe($setup['user']->id)
        ->and($activity->subject->is($exception))->toBeTrue()
        ->and($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->properties['reason'])->toBe('Handled separately.')
        ->and($activity->properties['reservation_unit_id'])->toBeNull()
        ->and(FolioLine::query()->count())->toBe(0);
});

test('Dismiss refuses a blank reason and checks a resolved exception first without logging again', function () {
    $setup = dismissSetup();
    $dismiss = app(DismissAuditException::class);

    expect($dismiss($setup['user'], $setup['exception'], '  ')->refusal)->toBe('reason_required')
        ->and($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
    expect($dismiss($setup['user'], $setup['exception'], 'Handled.')->isRefused())->toBeFalse()
        ->and($dismiss($setup['user'], $setup['exception'], '')->refusal)->toBe('exception_not_open')
        ->and(Activity::query()->where('event', 'night_audit.exception_dismissed')->count())->toBe(1)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('Dismiss validates the required reason and its maximum length', function (array $body) {
    $setup = dismissSetup();

    $this->actingAs($setup['user'])->postJson(route('night-audit.exceptions.dismiss', [$setup['property']->code, $setup['exception']]), $body)->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
})->with(['missing' => [[]], 'blank' => [['reason' => '  ']], 'too long' => [['reason' => str_repeat('x', 501)]]]);

test('Dismiss needs night-audit.run and an exception belonging to the property', function () {
    $setup = dismissSetup(false);
    $this->actingAs($setup['user'])->postJson(route('night-audit.exceptions.dismiss', [$setup['property']->code, $setup['exception']]), ['reason' => 'Handled.'])->assertForbidden();
    $owner = dismissSetup();
    $this->actingAs($owner['user'])->postJson(route('night-audit.exceptions.dismiss', [$owner['property']->code, $setup['exception']]), ['reason' => 'Handled.'])->assertNotFound();

    expect($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});
