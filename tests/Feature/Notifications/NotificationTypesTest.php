<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\User;
use App\Notifications\PropertyNotification;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;

class TestPropertyNotification extends PropertyNotification
{
    /**
     * @return array{message: string, type: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Test notification',
            'type' => $this->type->value,
        ];
    }
}

test('notification types define their delivery rules', function (
    NotificationType $type,
    array $channels,
    array $optOut,
    ?string $permission,
) {
    expect(array_map(fn (NotificationChannel $channel): string => $channel->value, $type->channels()))
        ->toBe($channels)
        ->and($type->defaultEnabled(NotificationChannel::Mail))->toBe(in_array('mail', $channels, true))
        ->and($type->defaultEnabled(NotificationChannel::Database))->toBe(in_array('database', $channels, true))
        ->and($type->canOptOut(NotificationChannel::Mail))->toBe($optOut['mail'])
        ->and($type->canOptOut(NotificationChannel::Database))->toBe($optOut['database'])
        ->and($type->recipientPermission())->toBe($permission);
})->with([
    'night audit completed' => [NotificationType::NightAuditCompleted, ['mail', 'database'], ['mail' => true, 'database' => true], 'night-audit.receive-report'],
    'night audit exceptions' => [NotificationType::NightAuditExceptions, ['mail', 'database'], ['mail' => true, 'database' => true], 'night-audit.receive-report'],
    'night audit failed' => [NotificationType::NightAuditFailed, ['mail', 'database'], ['mail' => true, 'database' => false], 'night-audit.run'],
    'registration card link' => [NotificationType::RegistrationCardLink, ['mail'], ['mail' => false, 'database' => false], null],
    'onboarding reminder' => [NotificationType::OnboardingReminder, ['mail', 'database'], ['mail' => true, 'database' => true], null],
    'dorm strategy changed' => [NotificationType::DormStrategyChanged, ['mail', 'database'], ['mail' => true, 'database' => true], null],
    'support access used' => [NotificationType::SupportAccessUsed, ['mail', 'database'], ['mail' => true, 'database' => false], null],
    'go-live approved' => [NotificationType::GoLiveApproved, ['mail', 'database'], ['mail' => true, 'database' => false], null],
    'go-live sent back' => [NotificationType::GoLiveRejected, ['mail', 'database'], ['mail' => true, 'database' => false], null],
    'portal booking created' => [NotificationType::PortalBookingCreated, ['mail', 'database'], ['mail' => true, 'database' => true], 'reservations.manage'],
    'portal booking cancelled' => [NotificationType::PortalBookingCancelled, ['mail', 'database'], ['mail' => true, 'database' => true], 'reservations.manage'],
    'portal cancellation requested' => [NotificationType::PortalCancellationRequested, ['mail', 'database'], ['mail' => true, 'database' => false], 'reservations.manage'],
    'channel booking received' => [NotificationType::ChannelBookingReceived, ['mail', 'database'], ['mail' => true, 'database' => true], 'reservations.manage'],
    'channel booking cancelled' => [NotificationType::ChannelBookingCancelled, ['mail', 'database'], ['mail' => true, 'database' => true], 'reservations.manage'],
]);

test('notification enums expose labelled options', function () {
    expect(NotificationChannel::options())->toBe([
        ['value' => 'mail', 'label' => 'Email'],
        ['value' => 'database', 'label' => 'In-app'],
    ])->and(NotificationType::options())->toBe([
        ['value' => 'night_audit_completed', 'label' => 'Night audit completed'],
        ['value' => 'night_audit_exceptions', 'label' => 'Night audit exceptions'],
        ['value' => 'night_audit_failed', 'label' => 'Night audit failed'],
        ['value' => 'registration_card_link', 'label' => 'Registration card link'],
        ['value' => 'onboarding_reminder', 'label' => 'Onboarding reminder'],
        ['value' => 'dorm_strategy_changed', 'label' => 'Dorm strategy changed'],
        ['value' => 'support_access_used', 'label' => 'Support access used'],
        ['value' => 'go_live_approved', 'label' => 'Go-live approved'],
        ['value' => 'go_live_rejected', 'label' => 'Go-live sent back'],
        ['value' => 'portal_booking_created', 'label' => 'Company portal booking'],
        ['value' => 'portal_booking_cancelled', 'label' => 'Company portal cancellation'],
        ['value' => 'portal_cancellation_requested', 'label' => 'Company cancellation request'],
        ['value' => 'channel_booking_received', 'label' => 'Channel booking received'],
        ['value' => 'channel_booking_cancelled', 'label' => 'Channel booking cancelled'],
    ]);
});

test('default preferences return enabled Laravel channels', function () {
    $user = User::factory()->make();

    $channels = app(NotificationPreferences::class)->channelsFor($user, NotificationType::NightAuditCompleted);

    expect($channels)->toBe(['mail', 'database']);
});

test('property notifications queue after commit and recheck the selected channel', function () {
    $user = User::factory()->make();
    $notification = new TestPropertyNotification(NotificationType::RegistrationCardLink);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($user))->toBe(['mail'])
        ->and($notification->shouldSend($user, 'mail'))->toBeTrue()
        ->and($notification->shouldSend($user, 'database'))->toBeFalse()
        ->and($notification->shouldSend($user, 'unknown'))->toBeFalse();
});

test('database notifications store their typed payload', function () {
    $user = User::factory()->create();

    $user->notifyNow(
        new TestPropertyNotification(NotificationType::NightAuditCompleted),
        ['database'],
    );

    $stored = $user->notifications()->sole();

    expect($stored->type)->toBe(TestPropertyNotification::class)
        ->and($stored->data)->toMatchArray([
            'message' => 'Test notification',
            'type' => 'night_audit_completed',
        ])
        ->and($stored->read_at)->toBeNull();
});
