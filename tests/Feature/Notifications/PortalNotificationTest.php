<?php

require_once __DIR__.'/../Support/PortalBookingSetup.php';

use App\Actions\Portal\NotifyReservationStaff;
use App\Enums\DefaultRoleEnum;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\PortalBookingCancelled;
use App\Notifications\PortalBookingCreated;
use App\Notifications\PortalCancellationRequested;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\Notification;

test('the reservation staff of the property get the portal messages and nobody else', function (string $notification) {
    $setup = portalBookingSetup();
    $reservation = portalMakeBooking($setup);
    Notification::fake();
    $property = $setup['property'];
    $manager = User::factory()->for($property)->withRole(DefaultRoleEnum::Manager)->create();
    $housekeeper = User::factory()->for($property)->withRole(DefaultRoleEnum::Housekeeping)->create();
    $deactivated = User::factory()->for($property)->withRole(DefaultRoleEnum::Manager)->deactivated()->create();
    $otherOwner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    app(NotifyReservationStaff::class)($reservation, $notification);

    Notification::assertSentTo([$setup['user'], $manager], $notification);
    Notification::assertNotSentTo([$housekeeper, $deactivated, $otherOwner, $admin, $setup['portalUser']], $notification);
})->with([PortalBookingCreated::class, PortalBookingCancelled::class, PortalCancellationRequested::class]);

test('a cancellation request is always in-app, and the other two can be switched off', function () {
    $setup = portalBookingSetup();
    $user = $setup['user'];
    $preferences = app(NotificationPreferences::class);

    foreach ([NotificationType::PortalBookingCreated, NotificationType::PortalCancellationRequested] as $type) {
        foreach ([NotificationChannel::Mail, NotificationChannel::Database] as $channel) {
            UserNotificationPreference::factory()->for($user)->create(['type' => $type, 'channel' => $channel, 'enabled' => false]);
        }
    }

    $user->refresh();

    expect($preferences->channelsFor($user, NotificationType::PortalBookingCreated))->toBe([])
        ->and($preferences->channelsFor($user, NotificationType::PortalCancellationRequested))->toBe(['database'])
        ->and($preferences->channelsFor($user, NotificationType::PortalBookingCancelled))->toBe(['mail', 'database']);
});

test('the message names the company, the reference and links to the reservation', function () {
    $setup = portalBookingSetup();
    $reservation = portalMakeBooking($setup);
    $message = new PortalBookingCreated($reservation->id, $reservation->reference, $setup['property']->code, $setup['property']->name, 'Borneo Travel', '2026-10-20');
    $url = route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $reservation->id]);

    expect($message->toArray($setup['user']))->toMatchArray([
        'type' => 'portal_booking_created',
        'title' => "Borneo Travel booked {$reservation->reference}",
        'reservation_id' => $reservation->id,
        'url' => $url,
    ])->and($message->toMail($setup['user'])->actionUrl)->toBe($url)
        ->and($message->toMail($setup['user'])->subject)->toContain('Borneo Travel');
});

test('the recipients are taken from this property only', function () {
    $setup = portalBookingSetup();
    $reservation = portalMakeBooking($setup);
    Notification::fake();
    $otherProperty = Property::factory()->create();
    $stranger = User::factory()->for($otherProperty)->withRole(DefaultRoleEnum::Owner)->create();

    app(NotifyReservationStaff::class)($reservation, PortalBookingCreated::class);

    Notification::assertSentTo($setup['user'], PortalBookingCreated::class);
    Notification::assertNotSentTo($stranger, PortalBookingCreated::class);
    expect(Reservation::query()->count())->toBe(1);
});
