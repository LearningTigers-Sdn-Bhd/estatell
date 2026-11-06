<?php

use App\Actions\Reservations\CreateReservation;
use App\Data\Reports\AgentPaymentDueRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reservations\NewReservationData;
use App\Enums\FolioStatus;
use App\Enums\PolicyScope;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\RoomRevenuePolicy;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Reports\AgentPaymentsReport;
use App\Services\Reservations\AgentPaymentSchedule;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Factories\BookingSourceFactory;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/BookingSetup.php';

const AGENT_TERMS = ['percent' => 50, 'hours' => 72, 'days' => 30];

/**
 * `bookingSetup()` with rates from 20 Dec, and a company that pays per booking.
 *
 * @param  array<string, mixed>  $company
 * @return array<string, mixed>
 */
function agentScheduleSetup(array $company = []): array
{
    $setup = bookingSetup();
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);

    for ($night = 0; $night < 3; $night++) {
        Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => CarbonImmutable::parse('2026-12-20')->addDays($night)->toDateString(), 'amount' => '500.00']);
    }

    return [...$setup, 'company' => Company::factory()->for($setup['property'])->create(['direct_bill_enabled' => false, ...$company])];
}

function agentBook(array $setup, string $arrival = '2026-12-20', string $departure = '2026-12-22', bool $fromChannel = false): Reservation
{
    return app(CreateReservation::class)($setup['user'], $setup['property'], NewReservationData::from([
        'bookerGuestId' => $setup['guest']->id,
        'bookingSource' => $fromChannel ? BookingSourceFactory::otaFor($setup['property']->id) : BookingSourceFactory::directFor($setup['property']->id),
        'companyId' => $setup['company']->id,
        'units' => [[
            'roomTypeId' => $setup['roomType']->id,
            'ratePlanId' => $setup['plan']->id,
            'arrivalDate' => $arrival,
            'departureDate' => $departure,
            'adults' => 2,
        ]],
    ]), $fromChannel)->reservation->refresh();
}

test('the deposit is due within the hold and the rest at the end of the day 30 days before arrival', function () {
    $settings = PropertySetting::factory()->make(['time_zone' => 'Asia/Kuala_Lumpur', 'check_in_time' => '15:00']);
    $schedule = app(AgentPaymentSchedule::class);

    $two = $schedule->deadlines(AGENT_TERMS, $settings, CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), CarbonImmutable::parse('2026-12-20'));
    $one = $schedule->deadlines(AGENT_TERMS, $settings, CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), CarbonImmutable::parse('2026-11-03'));
    $tomorrow = $schedule->deadlines(AGENT_TERMS, $settings, CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), CarbonImmutable::parse('2026-10-15'));
    $late = $schedule->deadlines(AGENT_TERMS, $settings, CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), CarbonImmutable::parse('2026-10-14'));

    expect($two['deposit_percent'])->toBe(50)
        ->and($two['deposit_due_at']->toIso8601String())->toBe('2026-10-17T12:00:00+00:00')
        ->and($two['full_payment_due_at']->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe('2026-11-20 23:59')
        ->and($one['deposit_percent'])->toBe(100)
        ->and($one['full_payment_due_at'])->toBeNull()
        ->and($tomorrow['deposit_due_at']->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe('2026-10-15 15:00')
        ->and($late['deposit_due_at']->toIso8601String())->toBe('2026-10-14T12:30:00+00:00');
});

test('a company that pays per booking gets the deadlines at booking, with its own terms', function () {
    $setup = agentScheduleSetup(['agent_deposit_percent' => 30]);

    $reservation = agentBook($setup);

    expect($reservation->deposit_percent)->toBe(30)
        ->and($reservation->deposit_due_at->toIso8601String())->toBe('2026-10-17T12:00:00+00:00')
        ->and($reservation->full_payment_due_at)->not->toBeNull();
});

test('direct billing, a schedule turned off and channel bookings get no deadlines', function (array $company, bool $fromChannel) {
    $setup = agentScheduleSetup($company);

    expect(agentBook($setup, fromChannel: $fromChannel)->deposit_due_at)->toBeNull();
})->with([
    'direct billing' => [['direct_bill_enabled' => true], false],
    'schedule off' => [['payment_schedule_enabled' => false], false],
    'channel' => [[], true],
]);

test('a stage is paid once the payments cover it, and overdue after its time', function () {
    $setup = agentScheduleSetup();
    $reservation = agentBook($setup);
    $schedule = app(AgentPaymentSchedule::class);

    $stages = $schedule->stagesFrom($reservation, BigDecimal::of('1000.00'), BigDecimal::of('0'));
    expect(array_map(fn (array $stage): array => [$stage['kind'], $stage['amount'], $stage['paid'], $stage['overdue']], $stages))->toBe([
        ['deposit', '500.00', false, false],
        ['balance', '500.00', false, false],
    ]);

    $later = $schedule->stagesFrom($reservation, BigDecimal::of('1000.00'), BigDecimal::of('500.00'), CarbonImmutable::parse('2026-11-25'));
    expect(array_map(fn (array $stage): array => [$stage['paid'], $stage['overdue']], $later))->toBe([[true, false], [false, true]]);
});

test('the total counts what lands on the company folio and the paid amount the payments of that folio', function () {
    $setup = agentScheduleSetup();
    $reservation = agentBook($setup);
    $folio = Folio::query()->where('reservation_id', $reservation->id)->whereNotNull('payer_company_id')->firstOrFail();
    FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => 'payment', 'amount' => '-300.00']);
    $schedule = app(AgentPaymentSchedule::class);

    expect((string) $schedule->total($reservation))->toBe('1000.00')
        ->and((string) $schedule->paid($reservation))->toBe('300.00')
        ->and($schedule->nextDue($reservation)['amount_due'])->toBe('200.00');
});

test('the report lists the bookings still owing a stage, overdue first, and the reservation shows its schedule', function () {
    $setup = agentScheduleSetup();
    $later = agentBook($setup);
    $sooner = agentBook($setup, '2026-10-21', '2026-10-23');
    $sooner->forceFill(['deposit_due_at' => '2026-10-13 00:00:00'])->save();

    $rows = app(AgentPaymentsReport::class)->rows($setup['property'], new ReportFilters(from: '2026-10-14', to: '2026-10-14'));

    expect(array_map(fn (AgentPaymentDueRow $row): array => [$row->reservation_id, $row->status], $rows))->toBe([
        [$sooner->id, 'Overdue'],
        [$later->id, 'Due'],
    ]);

    $this->actingAs($setup['user'])->get(route('reservations.show', [$setup['property']->code, $later]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSchedule.stages.0.label', 'Deposit 50%')->where('paymentSchedule.stages.0.amount', '500.00')->where('paymentSchedule.stages.1.status', 'due'));
});

test('after check-out a booking still owing stays on the report, without Release rooms, until its Master folio closes', function () {
    $setup = agentScheduleSetup();
    $booking = agentBook($setup);
    $booking->forceFill(['status' => ReservationStatus::Completed])->save();
    $rows = fn (): array => app(AgentPaymentsReport::class)->rows($setup['property'], new ReportFilters(from: '2026-10-14', to: '2026-10-14'));

    expect($rows())->toHaveCount(1)->and($rows()[0]->stay_ended)->toBeTrue();

    Folio::query()->where('reservation_id', $booking->id)->update(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $setup['user']->id]);

    expect($rows())->toBe([]);
});

test('a booking that moves to direct billing leaves the report and shows no schedule', function () {
    $setup = agentScheduleSetup();
    $booking = agentBook($setup);
    $booking->company->update(['direct_bill_enabled' => true]);

    expect(app(AgentPaymentsReport::class)->rows($setup['property'], new ReportFilters(from: '2026-10-14', to: '2026-10-14')))->toBe([]);
    $this->actingAs($setup['user'])->get(route('reservations.show', [$setup['property']->code, $booking]))
        ->assertInertia(fn (Assert $page) => $page->where('paymentSchedule.stages', []));
});

test('an overdue booking offers Release rooms, which cancels it from the report', function () {
    $setup = agentScheduleSetup();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id, 'date' => '2026-10-14']);
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::CancellationRevenue)->create();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('0.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();
    $late = agentBook($setup);
    $late->forceFill(['deposit_due_at' => '2026-10-13 00:00:00'])->save();
    $onTime = agentBook($setup);
    $report = route('reports.agent-payments', [$setup['property']->code, 'from' => '2026-10-14', 'to' => '2026-10-14']);

    $this->actingAs($setup['user'])->get($report)->assertInertia(fn (Assert $page) => $page
        ->where('canRelease', true)
        ->where('rows.data.0.reservation_id', $late->id)
        ->where('rows.data.0.overdue', true)
        ->where('rows.data.1.overdue', false));

    $this->from($report)->post(route('reservations.cancel', [$setup['property']->code, $late]), [
        'unit_ids' => $late->units()->pluck('id')->all(),
        'reason' => 'Deposit 50% not paid by the due time',
        'do_not_charge' => '0',
    ])->assertSessionHasNoErrors()->assertRedirect($report);

    expect($late->units()->first()->status->value)->toBe('cancelled')
        ->and($onTime->units()->first()->status->value)->toBe('confirmed');

    $viewer = User::factory()->for($setup['property'])->withPermissions(['reports.view'])->create();
    $this->actingAs($viewer)->get($report)->assertInertia(fn (Assert $page) => $page->where('canRelease', false));
});

test('the company credit tab sends the property terms that empty fields follow', function () {
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create(['agent_deposit_percent' => 40, 'agent_deposit_hours' => 48, 'agent_full_payment_days' => 21]);
    $company = Company::factory()->for($owner->property)->create(['direct_bill_enabled' => false, 'agent_deposit_hours' => 24]);

    $this->actingAs($owner)->get(route('finance.city-ledger.companies.credit', [$owner->property->code, $company]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.agentDepositHours', 24)
            ->where('agentPaymentDefaults', ['depositPercent' => 40, 'depositHours' => 48, 'fullPaymentDays' => 21]));
});

test('the company credit tab sends no terms while the property has no settings', function () {
    $owner = User::factory()->owner()->create();
    $company = Company::factory()->for($owner->property)->create();

    $this->actingAs($owner)->get(route('finance.city-ledger.companies.credit', [$owner->property->code, $company]))
        ->assertInertia(fn (Assert $page) => $page->where('agentPaymentDefaults', null));
});

test('owners save the property terms and a company its own', function () {
    $setup = agentScheduleSetup();
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();
    $company = Company::factory()->for($owner->property)->create(['direct_bill_enabled' => false]);

    $this->actingAs($owner)->put(route('settings.agent-payments.update', $owner->property->code), ['agent_deposit_percent' => 40, 'agent_deposit_hours' => 48, 'agent_full_payment_days' => 21])->assertSessionHasNoErrors();
    $this->put(route('settings.agent-payments.update', $owner->property->code), ['agent_deposit_percent' => 0, 'agent_deposit_hours' => 48, 'agent_full_payment_days' => 21])->assertSessionHasErrors('agent_deposit_percent');

    expect($owner->property->settings->refresh()->agent_deposit_percent)->toBe(40)->and($owner->property->settings->agent_full_payment_days)->toBe(21);

    $this->put(route('finance.city-ledger.companies.update', [$owner->property->code, $company, 'credit']), [
        'direct_bill_enabled' => '0', 'payment_schedule_enabled' => '1', 'agent_deposit_hours' => '24', 'agent_deposit_percent' => '', 'agent_full_payment_days' => '',
    ])->assertSessionHasNoErrors();

    expect($company->refresh()->agent_deposit_hours)->toBe(24)->and($company->agent_deposit_percent)->toBeNull()->and($company->payment_schedule_enabled)->toBeTrue();
});
