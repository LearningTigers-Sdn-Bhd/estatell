<?php

use App\Actions\CityLedger\SendAgentPaymentReminders;
use App\Actions\Reservations\CreateReservation;
use App\Data\Reservations\NewReservationData;
use App\Enums\CompanyUserStatus;
use App\Enums\SystemTransactionCode;
use App\Models\AgentPaymentReminder as SentReminder;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\User;
use App\Notifications\AgentPaymentReminder;
use Carbon\CarbonImmutable;
use Database\Factories\BookingSourceFactory;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/BookingSetup.php';

/**
 * An agent booking for 20 to 22 Dec made on 14 Oct 12:00 UTC: 50% by 17 Oct 12:00 UTC, the rest by 20 Nov.
 *
 * @return array{0: Reservation, 1: Company}
 */
function reminderBooking(): array
{
    Notification::fake();
    $setup = bookingSetup();
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);

    for ($night = 0; $night < 2; $night++) {
        Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => CarbonImmutable::parse('2026-12-20')->addDays($night)->toDateString(), 'amount' => '500.00']);
    }

    $company = Company::factory()->for($setup['property'])->create(['direct_bill_enabled' => false, 'contact_email' => 'ops@goodearth.my']);
    $reservation = app(CreateReservation::class)($setup['user'], $setup['property'], NewReservationData::from([
        'bookerGuestId' => $setup['guest']->id,
        'bookingSource' => BookingSourceFactory::directFor($setup['property']->id),
        'companyId' => $company->id,
        'units' => [['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-12-20', 'departureDate' => '2026-12-22', 'adults' => 2]],
    ]))->reservation;

    return [$reservation, $company];
}

/**
 * @return list<string>
 */
function remindersSent(): array
{
    return array_values(SentReminder::query()->orderBy('id')->get()->map(fn (SentReminder $row): string => $row->stage.':'.$row->kind)->all());
}

test('the agent gets the schedule once, a reminder in the day before the deposit, and a notice once it is late', function () {
    [, $company] = reminderBooking();
    $send = app(SendAgentPaymentReminders::class);

    expect($send(CarbonImmutable::parse('2026-10-14 12:15', 'UTC')))->toBe(1)
        ->and($send(CarbonImmutable::parse('2026-10-15 12:15', 'UTC')))->toBe(0)
        ->and($send(CarbonImmutable::parse('2026-10-16 13:00', 'UTC')))->toBe(1)
        ->and($send(CarbonImmutable::parse('2026-10-17 13:00', 'UTC')))->toBe(1)
        ->and($send(CarbonImmutable::parse('2026-10-18 13:00', 'UTC')))->toBe(0)
        ->and(remindersSent())->toBe(['all:schedule', 'deposit:reminder', 'deposit:overdue']);

    Notification::assertSentOnDemand(AgentPaymentReminder::class, fn (AgentPaymentReminder $mail, array $channels, AnonymousNotifiable $notifiable): bool => $mail->kind === 'overdue'
        && $mail->amountDue === '500.00'
        && $notifiable->routes['mail'] === ['ops@goodearth.my']);
});

test('the emails go to the contact email and the active portal logins, and skip a company with none', function () {
    [$reservation, $company] = reminderBooking();
    $login = User::factory()->companyUser()->create(['email' => 'rachel@goodearth.my']);
    CompanyUser::factory()->for($company)->create(['user_id' => $login->id, 'status' => CompanyUserStatus::Active, 'property_id' => $company->property_id]);

    app(SendAgentPaymentReminders::class)(CarbonImmutable::parse('2026-10-14 12:15', 'UTC'));

    Notification::assertSentOnDemand(AgentPaymentReminder::class, fn (AgentPaymentReminder $mail, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === ['ops@goodearth.my', 'rachel@goodearth.my']);

    SentReminder::query()->delete();
    $company->update(['contact_email' => null]);
    CompanyUser::query()->update(['status' => CompanyUserStatus::Revoked, 'revoked_at' => now()]);

    expect(app(SendAgentPaymentReminders::class)(CarbonImmutable::parse('2026-10-14 12:30', 'UTC')))->toBe(0);
});

test('a cancelled booking gets no emails and the command runs', function () {
    [$reservation] = reminderBooking();
    $reservation->forceFill(['status' => 'cancelled'])->save();

    $this->artisan('agent-payments:send-reminders')->assertSuccessful();

    expect(remindersSent())->toBe([]);
});

test('the schedule email lists every stage and links to the portal booking', function () {
    [$reservation] = reminderBooking();

    app(SendAgentPaymentReminders::class)(CarbonImmutable::parse('2026-10-14 12:15', 'UTC'));

    Notification::assertSentOnDemand(AgentPaymentReminder::class, function (AgentPaymentReminder $mail, array $channels, AnonymousNotifiable $notifiable) use ($reservation): bool {
        $html = (string) $mail->toMail($notifiable)->render();

        return str_contains($html, 'Deposit 50%: 500.00 by 17 Oct 2026, 20:00')
            && str_contains($html, 'Balance: 500.00 by 20 Nov 2026, 23:59')
            && str_contains($html, "/portal/{$reservation->property->code}/bookings/{$reservation->id}");
    });
});
