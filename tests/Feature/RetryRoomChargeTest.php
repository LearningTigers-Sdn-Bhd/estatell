<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\NightAudit\RetryRoomCharge;
use App\Enums\AuditExceptionType;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property trading on 2026-10-13. The audit of 2026-10-12 could not post the first night of a checked-in unit
 * of 2 nights from 2026-10-12, each night priced at 300.00, and left a room charge exception.
 *
 * @param  list<PermissionEnum>  $permissions
 * @return array{property: Property, user: User, unit: ReservationUnit, exception: NightAuditException}
 */
function retrySetup(array $permissions = [PermissionEnum::NightAuditRun, PermissionEnum::ReservationsManage]): array
{
    test()->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    $audited = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-13']);
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->checkedIn()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        'adults' => 2,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'amount' => '300.00', 'room_id' => Room::factory()->for($property)->create()->id]);
    $audit = NightAudit::factory()->completed()->create(['business_date_id' => $audited->id]);
    $exception = NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $unit->id, 'message' => 'folio_not_open']);

    $user = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Audit']);
    $role->givePermissionTo(array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions));
    setPermissionsTeamId($property->id);
    $user->assignRole($role);

    return ['property' => $property, 'user' => $user, 'unit' => $unit, 'exception' => $exception];
}

function retryUrl(Property $property, NightAuditException $exception): string
{
    return route('night-audit.exceptions.retry', [$property->code, $exception]);
}

test('Retry posts the missed night on the current business date, names the night and resolves the exception', function () {
    $setup = retrySetup();
    $night = $setup['unit']->nights()->orderBy('date')->firstOrFail();

    $this->actingAs($setup['user'])->postJson(retryUrl($setup['property'], $setup['exception']))
        ->assertOk()
        ->assertJsonPath('done', 1);

    $line = FolioLine::query()->where('idempotency_key', 'night:'.$night->id)->sole();
    $exception = $setup['exception']->refresh();

    expect($line->posting_date->toDateString())->toBe('2026-10-13')
        ->and($line->description)->toEndWith('· night of 12 Oct')
        ->and((string) $line->amount)->toBe('300.00')
        ->and($line->user_id)->toBe($setup['user']->id)
        ->and($exception->resolved_at)->not->toBeNull()
        ->and($exception->resolved_by)->toBe($setup['user']->id)
        ->and(Activity::query()->where('event', 'reservation.room_charge_retried')->sole()->properties['night'])->toBe('2026-10-12');

    $this->getJson(route('night-audit.show', $setup['property']->code))->assertJsonCount(0, 'warnings');
});

test('a refused Retry writes and logs nothing and gives the reason in words', function () {
    $setup = retrySetup();
    $folio = app(CreateFolio::class)($setup['unit']->reservation, $setup['unit']);
    $folio->forceFill(['status' => FolioStatus::Closed])->save();

    $this->actingAs($setup['user'])->postJson(retryUrl($setup['property'], $setup['exception']))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The folio was closed, so the night could not be charged.');

    expect(FolioLine::query()->count())->toBe(0)
        ->and($setup['exception']->refresh()->resolved_at)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.room_charge_retried')->exists())->toBeFalse();
});

test('a second Retry posts nothing more, and a night posted another way still resolves the exception', function () {
    $setup = retrySetup();
    $second = NightAuditException::factory()->create(['night_audit_id' => $setup['exception']->night_audit_id, 'reservation_unit_id' => $setup['unit']->id]);
    $retry = app(RetryRoomCharge::class);

    $first = $retry($setup['user'], $setup['exception']);
    $count = FolioLine::query()->count();
    $again = $retry($setup['user'], $setup['exception']);
    $replayed = $retry($setup['user'], $second);

    expect($first->isRefused())->toBeFalse()
        ->and($again->refusal)->toBe(RetryRoomCharge::REFUSAL_EXCEPTION_NOT_RETRIED)
        ->and($replayed->replayed)->toBeTrue()
        ->and($second->refresh()->resolved_at)->not->toBeNull()
        ->and(FolioLine::query()->count())->toBe($count);
});

test('Retry posts the missed night of a unit that has checked out since', function () {
    $setup = retrySetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();

    expect(app(RetryRoomCharge::class)($setup['user'], $setup['exception'])->isRefused())->toBeFalse();
});

test('only an open room charge exception can be retried', function () {
    $setup = retrySetup();
    $setup['exception']->forceFill(['type' => AuditExceptionType::MissedArrival])->save();

    expect(app(RetryRoomCharge::class)($setup['user'], $setup['exception'])->refusal)->toBe(RetryRoomCharge::REFUSAL_EXCEPTION_NOT_RETRIED)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('the sheet offers Retry on a carried room charge only', function () {
    $setup = retrySetup();
    $otherUnit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->withNights()->checkedIn()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    $otherUnit->nights()->update(['room_id' => Room::factory()->for($setup['property'])->create()->id]);
    $other = NightAuditException::factory()->create([
        'night_audit_id' => $setup['exception']->night_audit_id,
        'reservation_unit_id' => $otherUnit->id,
        'type' => AuditExceptionType::MissedArrival,
        'message' => 'policy_not_found',
    ]);

    $warnings = collect($this->actingAs($setup['user'])->getJson(route('night-audit.show', $setup['property']->code))->json('warnings'))
        ->where('check', 'carried_exception')
        ->keyBy('reservationId');

    expect(array_column($warnings[$setup['unit']->reservation_id]['actions'], 'label'))->toBe(['Retry', 'Open'])
        ->and($warnings[$setup['unit']->reservation_id]['actions'][0]['url'])->toBe(route('night-audit.exceptions.retry', [$setup['property']->code, $setup['exception']], absolute: false))
        ->and(array_column($warnings[$other->reservationUnit->reservation_id]['actions'], 'label'))->toBe(['Open']);
});

test('Retry needs reservations.manage and an exception of the property', function () {
    $setup = retrySetup([PermissionEnum::NightAuditRun]);

    $this->actingAs($setup['user'])->postJson(retryUrl($setup['property'], $setup['exception']))->assertForbidden();

    $owner = retrySetup();
    $this->actingAs($owner['user'])->postJson(route('night-audit.exceptions.retry', [$owner['property']->code, $setup['exception']]))->assertNotFound();

    expect($setup['exception']->refresh()->resolved_at)->toBeNull();
});
