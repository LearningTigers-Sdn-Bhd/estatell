<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\GoLiveReview;
use App\Models\Property;
use App\Models\User;
use App\Notifications\OnboardingReminder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('sends the 24 hour reminder once and the 72 hour reminder once', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->travel(23)->hours();
    $this->artisan('onboarding:send-reminders')->assertSuccessful();
    Notification::assertNothingSentTo($owner);

    $this->travel(1)->hours();
    $this->artisan('onboarding:send-reminders')->assertSuccessful();
    Notification::assertSentToTimes($owner, OnboardingReminder::class, 1);
    expect($owner->property->refresh()->onboarding_reminder_stage)->toBe(1);

    $this->travel(48)->hours();
    $this->artisan('onboarding:send-reminders')->assertSuccessful();
    Notification::assertSentToTimes($owner, OnboardingReminder::class, 2);
    expect($owner->property->refresh()->onboarding_reminder_stage)->toBe(2);

    $this->travel(200)->hours();
    $this->artisan('onboarding:send-reminders')->assertSuccessful();
    Notification::assertSentToTimes($owner, OnboardingReminder::class, 2);
});

it('sends nothing twice when the command runs again in the same hour', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->travel(25)->hours();
    $this->artisan('onboarding:send-reminders');
    $this->artisan('onboarding:send-reminders');

    Notification::assertSentToTimes($owner, OnboardingReminder::class, 1);
});

it('counts from the last activity when the owner has been active', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding()->create([
        'onboarding_started_at' => now()->subDays(3),
    ]))->create();
    $owner->property->forceFill(['onboarding_last_activity_at' => now()])->save();

    $this->artisan('onboarding:send-reminders');

    Notification::assertNothingSentTo($owner);
});

it('counts from the start of onboarding when there has been no activity', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding()->create([
        'onboarding_started_at' => now()->subHours(25),
    ]))->create();

    $this->artisan('onboarding:send-reminders');

    Notification::assertSentToTimes($owner, OnboardingReminder::class, 1);
});

it('skips a property with a pending go-live request', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    GoLiveReview::factory()->create(['property_id' => $owner->property_id, 'submitted_by' => $owner->id]);

    $this->travel(25)->hours();
    $this->artisan('onboarding:send-reminders');

    Notification::assertNothingSentTo($owner);
    expect($owner->property->refresh()->onboarding_reminder_stage)->toBe(0);
});

it('skips a suspended property', function () {
    $owner = User::factory()->owner()->for(Property::factory()->suspended())->create();

    $this->travel(25)->hours();
    $this->artisan('onboarding:send-reminders');

    Notification::assertNothingSentTo($owner);
});

it('skips a live property', function () {
    $owner = User::factory()->owner()->for(Property::factory()->live())->create();

    $this->travel(200)->hours();
    $this->artisan('onboarding:send-reminders');

    Notification::assertNothingSentTo($owner);
});

it('skips an owner who turned the reminder off, and keeps the stage', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    foreach (NotificationChannel::cases() as $channel) {
        $owner->notificationPreferences()->create([
            'type' => NotificationType::OnboardingReminder,
            'channel' => $channel,
            'enabled' => false,
        ]);
    }

    $this->travel(25)->hours();
    $this->artisan('onboarding:send-reminders');

    Notification::assertNothingSentTo($owner);
    expect($owner->property->refresh()->onboarding_reminder_stage)->toBe(0);
});

it('sends the setup link to an owner who has not verified the email', function () {
    $owner = User::factory()->unverified()->owner()->for(Property::factory()->onboarding())->create();

    $this->travel(25)->hours();
    $this->artisan('onboarding:send-reminders');

    Notification::assertSentTo($owner, OnboardingReminder::class, fn (OnboardingReminder $notification): bool => $notification->usesSetupLink
        && str_starts_with($notification->actionUrl, route('guest.owner-setup.show', ['user' => $owner])));
});

it('links an owner with a verified email to the next unfinished step', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->travel(25)->hours();
    $this->artisan('onboarding:send-reminders');

    Notification::assertSentTo($owner, OnboardingReminder::class, fn (OnboardingReminder $notification): bool => ! $notification->usesSetupLink
        && $notification->stepLabel === 'Basics'
        && $notification->actionUrl === route('onboarding.basics.edit', $owner->property->code));
});

it('sends one reminder, at stage 2, to a property idle for more than 72 hours', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->travel(100)->hours();
    $this->artisan('onboarding:send-reminders')->assertSuccessful();
    $this->artisan('onboarding:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($owner, OnboardingReminder::class, 1);
    expect($owner->property->refresh()->onboarding_reminder_stage)->toBe(2);
});
