<?php

use App\Actions\NightAudit\NotifyNightAuditResult;
use App\Enums\AuditStatus;
use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\DailyRevenue;
use App\Models\DailyStatistic;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\MealPeriod;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\NightAuditStep;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertyNotificationSetting;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\TransactionCode;
use App\Models\User;
use App\Notifications\NightAuditCompleted;
use App\Notifications\NightAuditExceptions;
use App\Notifications\NightAuditFailed;
use App\Services\NightAudit\AuditResultSummary;
use Illuminate\Support\Facades\Notification;

/**
 * A live property whose business date 2026-10-12 is closed, with a completed audit and an Owner who receives the report.
 *
 * @return array{property: Property, audit: NightAudit, owner: User}
 */
function notifySetup(string $state = 'completed'): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    $date = BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $audit = NightAudit::factory()->{$state}()->create(['business_date_id' => $date->id]);
    $owner = User::factory()->for($property)->withRole(DefaultRoleEnum::Owner)->create();

    return ['property' => $property, 'audit' => $audit, 'owner' => $owner];
}

/**
 * A staff user of the property who holds only the given permissions.
 *
 * @param  list<PermissionEnum>  $permissions
 */
function notifyStaff(Property $property, array $permissions): User
{
    $user = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Role '.fake()->unique()->word()]);
    $role->givePermissionTo(array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions));
    setPermissionsTeamId($property->id);
    $user->assignRole($role);

    return $user;
}

test('a completed audit tells the staff who receive the report and nobody else', function () {
    Notification::fake();
    ['property' => $property, 'audit' => $audit, 'owner' => $owner] = notifySetup();
    $receiver = notifyStaff($property, [PermissionEnum::NightAuditReceiveReport]);
    $runner = notifyStaff($property, [PermissionEnum::NightAuditRun]);
    $deactivated = User::factory()->for($property)->withRole(DefaultRoleEnum::Manager)->deactivated()->create();
    $otherOwner = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $admin = User::factory()->admin()->create();

    $sent = app(NotifyNightAuditResult::class)($audit);

    expect($sent)->toBe(NotificationType::NightAuditCompleted);
    Notification::assertSentTo([$owner, $receiver], NightAuditCompleted::class);
    Notification::assertNotSentTo([$runner, $deactivated, $otherOwner, $admin], NightAuditCompleted::class);
    Notification::assertCount(2);
});

test('a completed audit with an open exception sends the exceptions message, and a resolved one does not', function () {
    Notification::fake();
    ['audit' => $audit, 'owner' => $owner] = notifySetup();
    NightAuditException::factory()->resolved()->create(['night_audit_id' => $audit->id]);

    app(NotifyNightAuditResult::class)($audit);
    Notification::assertSentTo($owner, NightAuditCompleted::class);
    Notification::assertNotSentTo($owner, NightAuditExceptions::class);

    NightAuditException::factory()->create(['night_audit_id' => $audit->id]);
    app(NotifyNightAuditResult::class)($audit);

    Notification::assertSentToTimes($owner, NightAuditExceptions::class, 1);
    Notification::assertSentToTimes($owner, NightAuditCompleted::class, 1);
});

test('a failed audit tells the staff who can run the audit, and a running audit tells nobody', function () {
    Notification::fake();
    ['property' => $property, 'audit' => $audit, 'owner' => $owner] = notifySetup('failed');
    $runner = notifyStaff($property, [PermissionEnum::NightAuditRun]);
    $receiver = notifyStaff($property, [PermissionEnum::NightAuditReceiveReport]);

    $sent = app(NotifyNightAuditResult::class)($audit);

    expect($sent)->toBe(NotificationType::NightAuditFailed);
    Notification::assertSentTo([$owner, $runner], NightAuditFailed::class);
    Notification::assertNotSentTo($receiver, NightAuditFailed::class);

    $audit->forceFill(['status' => AuditStatus::Running])->save();

    expect(app(NotifyNightAuditResult::class)($audit))->toBeNull();
    Notification::assertCount(2);
});

test('the message follows the property and user preferences', function () {
    ['property' => $property, 'audit' => $audit, 'owner' => $owner] = notifySetup();
    PropertyNotificationSetting::factory()->for($property)->create([
        'type' => NotificationType::NightAuditCompleted,
        'channel' => NotificationChannel::Mail,
        'enabled' => false,
    ]);
    PropertyNotificationSetting::factory()->for($property)->create([
        'type' => NotificationType::NightAuditFailed,
        'channel' => NotificationChannel::Database,
        'enabled' => false,
    ]);

    expect((new NightAuditCompleted($audit))->via($owner->refresh()))->toBe(['database'])
        ->and((new NightAuditFailed($audit))->via($owner->refresh()))->toBe(['mail', 'database']);
});

test('the message carries the figures, revenue, payments, meals and exceptions of the audit', function () {
    ['property' => $property, 'audit' => $audit, 'owner' => $owner] = notifySetup();
    DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-12', 'occupancy' => '62.50', 'adr' => '180.00', 'revpar' => '112.50', 'arrivals' => 3, 'departures' => 2, 'no_shows' => 1, 'in_house_guests' => 9]);
    $room = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create(['name' => 'Room revenue']);
    $laundry = TransactionCode::factory()->for($property)->system(SystemTransactionCode::LaundryRevenue)->create(['name' => 'Laundry']);
    DailyRevenue::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-12', 'transaction_code_id' => $room->id, 'amount' => '900.00']);
    DailyRevenue::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-12', 'transaction_code_id' => $laundry->id, 'amount' => '35.50']);
    DailyRevenue::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-11', 'transaction_code_id' => $room->id, 'amount' => '777.00']);

    $folio = Folio::factory()->create(['property_id' => $property->id]);
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Cash']);
    $card = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Card']);
    $paid = fn (PaymentMethod $method, string $amount, string $date, ?int $refundOf = null): Payment => Payment::factory()->create([
        'folio_id' => $folio->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'refund_of_id' => $refundOf,
        ...($refundOf === null ? [] : ['receipt_number' => null]),
        'folio_line_id' => FolioLine::factory()->payment($amount)->create(['folio_id' => $folio->id, 'transaction_code_id' => $method->transaction_code_id, 'posting_date' => $date])->id,
    ]);
    $first = $paid($cash, '200.00', '2026-10-12');
    $paid($cash, '50.00', '2026-10-12');
    $paid($card, '300.00', '2026-10-12');
    $paid($card, '999.00', '2026-10-11');
    ArPayment::factory()->create(['property_id' => $property->id, 'payment_method_id' => $cash->id, 'amount' => '100.00', 'received_on' => '2026-10-12']);
    ArPayment::factory()->voided()->create(['property_id' => $property->id, 'payment_method_id' => $cash->id, 'amount' => '77.00', 'received_on' => '2026-10-12']);
    ArPayment::factory()->create(['property_id' => $property->id, 'payment_method_id' => $cash->id, 'amount' => '66.00', 'received_on' => '2026-10-11']);
    $paid($cash, '20.00', '2026-10-12', $first->id);

    $breakfast = MealPeriod::factory()->create(['property_id' => $property->id, 'name' => 'Breakfast', 'starts_at' => '07:00']);
    $dinner = MealPeriod::factory()->create(['property_id' => $property->id, 'name' => 'Dinner', 'starts_at' => '19:00', 'ends_at' => '20:00']);
    $plan = RatePlan::factory()->create(['property_id' => $property->id]);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $plan->id, 'meal_period_id' => $dinner->id, 'starts_on' => InclusionStart::ArrivalDay, 'ends_on' => InclusionEnd::NightBeforeDeparture]);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $plan->id, 'meal_period_id' => $breakfast->id, 'starts_on' => InclusionStart::DayAfterArrival, 'ends_on' => InclusionEnd::DepartureDay]);
    $unit = fn (string $status, string $arrival, string $departure, int $adults, int $children = 0) => ReservationUnit::factory()
        ->for(Reservation::factory()->create(['property_id' => $property->id]))
        ->{$status}()
        ->create(['rate_plan_id' => $plan->id, 'arrival_date' => $arrival, 'departure_date' => $departure, 'adults' => $adults, 'children' => $children]);
    $unit('checkedIn', '2026-10-12', '2026-10-15', 2, 1);
    $unit('checkedIn', '2026-10-10', '2026-10-13', 2);
    $unit('cancelled', '2026-10-12', '2026-10-15', 4);
    $unit('checkedOut', '2026-10-10', '2026-10-12', 4);

    $exception = NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $unit('checkedIn', '2026-10-12', '2026-10-14', 1)->id]);

    $mail = (new NightAuditExceptions($audit))->toMail($owner)->render()->toHtml();
    $mailText = preg_replace('/\s+/', ' ', strip_tags($mail));
    $array = (new NightAuditExceptions($audit))->toArray($owner);

    expect($mailText)->toContain('Occupancy', '62.50%', 'MYR 180.00', 'MYR 112.50')
        ->toContain('Arrivals: 3', 'No-shows: 1', 'In-house guests: 9')
        ->toContain('Room revenue MYR 900.00', 'Laundry MYR 35.50')
        ->not->toContain('777.00')
        ->toContain('Cash MYR 350.00', 'Card MYR 300.00', 'Refunds MYR 20.00')
        ->not->toContain('999.00', '77.00', '66.00')
        ->toContain('Meals to prepare on Oct 13, 2026', 'Breakfast 6', 'Dinner 4')
        ->toContain($exception->reservationUnit->reservation->reference, 'folio was closed')
        ->toContain(route('night-audit.show', $property->code))
        ->and(strpos($mail, 'Breakfast'))->toBeLessThan(strpos($mail, 'Dinner'))
        ->and($array)->toMatchArray(['type' => 'night_audit_exceptions', 'business_date' => '2026-10-12', 'open_exceptions' => 1]);
});

test('a city ledger payment shows in the payments by method, merged with the folio payments of the same method', function () {
    ['property' => $property, 'audit' => $audit, 'owner' => $owner] = notifySetup();
    $bank = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Bank transfer']);
    ArPayment::factory()->create(['property_id' => $property->id, 'payment_method_id' => $bank->id, 'amount' => '500.00', 'received_on' => '2026-10-12']);

    $mail = (new NightAuditCompleted($audit))->toMail($owner)->render()->toHtml();
    $mailText = preg_replace('/\s+/', ' ', strip_tags($mail));
    $summary = app(AuditResultSummary::class)->forAudit($audit);

    expect($mailText)->toContain('Payments by method', 'Bank transfer MYR 500.00')
        ->and($summary->payments)->toHaveCount(1)
        ->and($summary->payments[0]['count'])->toBe(1);
});

test('the packet option lists resolved exceptions and the message keeps only the open ones', function () {
    ['audit' => $audit] = notifySetup();
    NightAuditException::factory()->resolved()->create(['night_audit_id' => $audit->id]);
    NightAuditException::factory()->create(['night_audit_id' => $audit->id]);

    expect(app(AuditResultSummary::class)->forAudit($audit)->exceptions)->toHaveCount(1)
        ->and(app(AuditResultSummary::class)->forAudit($audit, allExceptions: true)->exceptions)->toHaveCount(2);
});

test('a failed message names the step and the error', function () {
    ['audit' => $audit, 'owner' => $owner] = notifySetup('failed');
    NightAuditStep::factory()->create([
        'night_audit_id' => $audit->id,
        'step' => AuditStep::SnapshotStatistics,
        'status' => AuditStepStatus::Failed,
        'error_message' => 'RuntimeException: snapshot boom',
    ]);

    $message = (new NightAuditFailed($audit))->toMail($owner);

    expect($message->subject)->toBe('Night audit failed for Oct 12, 2026')
        ->and($message->render()->toHtml())->toContain('Snapshot statistics', 'RuntimeException: snapshot boom', 'resume');
});
