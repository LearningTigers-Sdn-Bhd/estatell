<?php

use App\Actions\Notifications\UpdatePropertyNotificationSetting;
use App\Actions\Notifications\UpdateUserNotificationPreference;
use App\Enums\DefaultRoleEnum;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\PropertyNotificationSetting;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\NotificationPreferences;
use Inertia\Testing\AssertableInertia as Assert;

test('notification channels resolve enum defaults without stored preferences', function () {
    $user = User::factory()->create();
    $preferences = app(NotificationPreferences::class);

    expect($preferences->channelsFor($user, NotificationType::NightAuditCompleted))->toBe(['mail', 'database'])
        ->and($preferences->channelsFor($user, NotificationType::RegistrationCardLink))->toBe(['mail']);
});

test('property and user opt-outs resolve in precedence order', function () {
    $propertyUser = User::factory()->create();
    $otherUser = User::factory()->for($propertyUser->property)->create();
    $preferences = app(NotificationPreferences::class);

    PropertyNotificationSetting::factory()->for($propertyUser->property)->create([
        'type' => NotificationType::NightAuditCompleted,
        'channel' => NotificationChannel::Mail,
        'enabled' => false,
    ]);
    UserNotificationPreference::factory()->for($propertyUser)->create([
        'type' => NotificationType::NightAuditCompleted,
        'channel' => NotificationChannel::Database,
        'enabled' => false,
    ]);

    expect($preferences->channelsFor($propertyUser->refresh(), NotificationType::NightAuditCompleted))->toBe([])
        ->and($preferences->channelsFor($otherUser->refresh(), NotificationType::NightAuditCompleted))->toBe(['database']);
});

test('mandatory and unsupported channels ignore malformed overrides', function () {
    $user = User::factory()->create();
    $preferences = app(NotificationPreferences::class);

    PropertyNotificationSetting::factory()->for($user->property)->create([
        'type' => NotificationType::RegistrationCardLink,
        'channel' => NotificationChannel::Mail,
        'enabled' => false,
    ]);
    UserNotificationPreference::factory()->for($user)->create([
        'type' => NotificationType::RegistrationCardLink,
        'channel' => NotificationChannel::Database,
        'enabled' => true,
    ]);

    expect($preferences->channelsFor($user->refresh(), NotificationType::RegistrationCardLink))->toBe(['mail'])
        ->and($preferences->propertyEnabled($user->property, NotificationType::RegistrationCardLink, NotificationChannel::Database))->toBeFalse();
});

test('platform admins receive no property notification channels', function () {
    $admin = User::factory()->admin()->create();

    expect(app(NotificationPreferences::class)->channelsFor($admin, NotificationType::NightAuditCompleted))->toBe([]);
});

test('property setting action stores only non-default opt-outs', function () {
    $property = User::factory()->create()->property;
    $update = app(UpdatePropertyNotificationSetting::class);

    expect($update($property, NotificationType::NightAuditCompleted, NotificationChannel::Mail, false))->toBeTrue()
        ->and(PropertyNotificationSetting::query()->sole()->enabled)->toBeFalse();

    expect($update($property, NotificationType::NightAuditCompleted, NotificationChannel::Mail, true))->toBeTrue()
        ->and(PropertyNotificationSetting::query()->count())->toBe(0);
});

test('property setting action refuses unsupported and mandatory cells', function () {
    $property = User::factory()->create()->property;
    $update = app(UpdatePropertyNotificationSetting::class);

    expect($update($property, NotificationType::RegistrationCardLink, NotificationChannel::Database, true))->toBeFalse()
        ->and($update($property, NotificationType::RegistrationCardLink, NotificationChannel::Mail, false))->toBeFalse()
        ->and(PropertyNotificationSetting::query()->count())->toBe(0);
});

test('user preference action stores sparse overrides and isolates users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->for($user->property)->create();
    $update = app(UpdateUserNotificationPreference::class);

    expect($update($user, NotificationType::NightAuditCompleted, NotificationChannel::Mail, false))->toBeTrue()
        ->and(UserNotificationPreference::query()->sole()->enabled)->toBeFalse()
        ->and(app(NotificationPreferences::class)->channelsFor($otherUser, NotificationType::NightAuditCompleted))->toBe(['mail', 'database']);

    expect($update($user, NotificationType::NightAuditCompleted, NotificationChannel::Mail, true))->toBeTrue()
        ->and(UserNotificationPreference::query()->count())->toBe(0);
});

test('user preference action refuses locked cells without writing', function () {
    $user = User::factory()->create();
    PropertyNotificationSetting::factory()->for($user->property)->create([
        'type' => NotificationType::NightAuditCompleted,
        'channel' => NotificationChannel::Mail,
        'enabled' => false,
    ]);
    $update = app(UpdateUserNotificationPreference::class);

    expect($update($user, NotificationType::NightAuditCompleted, NotificationChannel::Mail, false))->toBeFalse()
        ->and($update($user, NotificationType::RegistrationCardLink, NotificationChannel::Database, true))->toBeFalse()
        ->and($update($user, NotificationType::RegistrationCardLink, NotificationChannel::Mail, false))->toBeFalse()
        ->and(UserNotificationPreference::query()->count())->toBe(0);
});

test('owners view and update the property notification matrix', function () {
    $owner = User::factory()->owner()->create();
    $route = route('settings.notifications.index', $owner->property->code);

    $this->actingAs($owner)->get($route)
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/notifications')
            ->has('preferences', 7)
            ->where('preferences.0.type', 'night_audit_completed')
            ->where('preferences.0.channels.0', [
                'channel' => 'mail',
                'label' => 'Email',
                'supported' => true,
                'enabled' => true,
                'editable' => true,
                'disabledReason' => null,
            ])
            ->where('navigation.contexts.3.groups.5.items.0.title', 'Notifications'));

    $this->put(route('settings.notifications.update', [$owner->property->code, 'night_audit_completed', 'mail']), ['enabled' => false])
        ->assertRedirect($route)
        ->assertSessionHasNoErrors();

    expect(PropertyNotificationSetting::query()->sole()->enabled)->toBeFalse();
});

test('property notification routes enforce permission tenant and input boundaries', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $owner = User::factory()->owner()->create();

    $this->actingAs($manager)->get(route('settings.notifications.index', $manager->property->code))->assertForbidden();
    $this->put(route('settings.notifications.update', [$manager->property->code, 'night_audit_completed', 'mail']), ['enabled' => false])->assertForbidden();

    $this->actingAs($owner)->get(route('settings.notifications.index', $manager->property->code))->assertNotFound();
    $this->put(route('settings.notifications.update', [$owner->property->code, 'invalid', 'mail']), ['enabled' => false])->assertNotFound();
    $this->put(route('settings.notifications.update', [$owner->property->code, 'night_audit_completed', 'invalid']), ['enabled' => false])->assertNotFound();
    $this->put(route('settings.notifications.update', [$owner->property->code, 'night_audit_completed', 'mail']), ['enabled' => 'yes'])->assertSessionHasErrors('enabled');
});

test('verified staff manage only their own account preferences', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('account.notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/notifications')
            ->has('preferences', 7)
            ->where('property.code', $staff->property->code));

    $this->put(route('account.notifications.update', ['night_audit_completed', 'database']), ['enabled' => false])
        ->assertRedirect(route('account.notifications.edit'))
        ->assertSessionHasNoErrors();

    expect(UserNotificationPreference::query()->sole()->user_id)->toBe($staff->id);
});

test('account notification routes exclude admins and unverified users', function () {
    $admin = User::factory()->admin()->create();
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($admin)->get(route('account.notifications.edit'))->assertNotFound();
    $this->put(route('account.notifications.update', ['night_audit_completed', 'mail']), ['enabled' => false])->assertNotFound();

    $this->actingAs($unverified)->get(route('account.notifications.edit'))->assertRedirect(route('verification.notice'));
    $this->put(route('account.notifications.update', ['night_audit_completed', 'mail']), ['enabled' => false])->assertRedirect(route('verification.notice'));
});
