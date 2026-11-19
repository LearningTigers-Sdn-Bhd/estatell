<?php

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Enums\DefaultRoleEnum;
use App\Models\EmailOtp;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrustedDevice;
use App\Notifications\NewDeviceCodeNotification;
use App\Services\Account\TrustedDevices;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/DeviceCheck.php';

/**
 * Sign in with the password and return the code the email carried.
 */
function signInOnNewDevice(User $user, array $input = []): string
{
    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password', ...$input])
        ->assertRedirect(route('login.otp.show'));

    $code = null;
    Notification::assertSentTo($user, NewDeviceCodeNotification::class, function (NewDeviceCodeNotification $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    return $code;
}

beforeEach(function () {
    Notification::fake();
    $this->staff = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['email' => 'aina@pulauinn.my']);
});

test('a new device gets an emailed code and nobody is signed in yet', function () {
    $code = signInOnNewDevice($this->staff);

    $this->assertGuest();
    expect($code)->toMatch('/^\d{6}$/')
        ->and(EmailOtp::query()->sole()->code_hash)->not->toBe($code);

    $this->get(route('login.otp.show'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/verify-device')
        ->where('email', 'a***@pulauinn.my')
        ->where('resendInSeconds', 60));
});

test('the right code signs in, trusts the browser for 90 days and goes home', function () {
    $code = signInOnNewDevice($this->staff, ['remember' => 'on']);

    $response = $this->post(route('login.otp.store'), ['code' => $code])
        ->assertRedirect(route('home.redirect', absolute: false))
        ->assertCookie(TrustedDevices::COOKIE);

    $this->assertAuthenticatedAs($this->staff);
    $device = UserTrustedDevice::query()->sole();
    expect($device->user_id)->toBe($this->staff->id)
        ->and($device->expires_at->equalTo(now()->addDays(90)))->toBeTrue()
        ->and($device->token_hash)->toBe(hash('sha256', $response->getCookie(TrustedDevices::COOKIE)->getValue()))
        ->and(EmailOtp::query()->exists())->toBeFalse()
        ->and($this->staff->refresh()->remember_token)->not->toBeNull();
});

test('a trusted browser signs in without a code and moves the trust forward', function () {
    trustTestBrowserFor($this->staff);
    UserTrustedDevice::query()->update(['expires_at' => now()->addDay()]);

    $this->post(route('login.store'), ['email' => $this->staff->email, 'password' => 'password'])
        ->assertRedirect(route('home.redirect', absolute: false));

    $this->assertAuthenticatedAs($this->staff);
    Notification::assertNothingSent();
    expect(UserTrustedDevice::query()->sole()->expires_at->equalTo(now()->addDays(90)))->toBeTrue();
});

test('one browser stays trusted for each user who verified on it', function () {
    $other = User::factory()->for($this->staff->property)->create();
    $token = trustTestBrowserFor($this->staff);

    $code = signInOnNewDevice($other);
    $this->post(route('login.otp.store'), ['code' => $code])->assertRedirect();

    expect(UserTrustedDevice::query()->pluck('token_hash')->unique()->all())->toBe([hash('sha256', $token)])
        ->and(UserTrustedDevice::query()->count())->toBe(2);
});

test('an expired trust or another user\'s trust asks for a code', function () {
    $other = User::factory()->for($this->staff->property)->create();
    $token = trustTestBrowserFor($other);
    UserTrustedDevice::factory()->for($this->staff)->create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->subMinute()]);

    signInOnNewDevice($this->staff);

    $this->assertGuest();
});

test('a wrong code is refused and counted, and five wrong codes use the code up', function () {
    $code = signInOnNewDevice($this->staff);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.otp.store'), ['code' => $wrong])->assertSessionHasErrors('code');
    }

    expect(EmailOtp::query()->sole()->attempts)->toBe(5);

    $this->post(route('login.otp.store'), ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('an expired code is refused', function () {
    $code = signInOnNewDevice($this->staff);
    $this->travel(16)->minutes();

    $this->post(route('login.otp.store'), ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a staff user deactivated while the code is pending is not signed in', function () {
    $code = signInOnNewDevice($this->staff);
    $this->staff->forceFill(['deactivated_at' => now()])->save();

    $this->post(route('login.otp.store'), ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a new code can be sent once a minute and replaces the old one', function () {
    $first = signInOnNewDevice($this->staff);

    $this->post(route('login.otp.resend'))->assertInertiaFlash('toast.type', 'error');
    Notification::assertSentToTimes($this->staff, NewDeviceCodeNotification::class, 1);

    $this->travel(61)->seconds();
    $this->post(route('login.otp.resend'))->assertInertiaFlash('toast.type', 'success');
    Notification::assertSentToTimes($this->staff, NewDeviceCodeNotification::class, 2);

    $this->post(route('login.otp.store'), ['code' => $first])->assertSessionHasErrors('code');
});

test('the verify page and its actions need a pending sign-in', function () {
    $this->get(route('login.otp.show'))->assertRedirect(route('login'));
    $this->post(route('login.otp.store'), ['code' => '123456'])->assertRedirect(route('login'));
    $this->post(route('login.otp.resend'))->assertRedirect(route('login'));
    Notification::assertNothingSent();
});

test('platform admins sign in without a code', function () {
    $admin = User::factory()->admin()->create();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($admin);
    Notification::assertNothingSent();
});

test('a user with two-factor gets the two-factor challenge instead of a code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    Notification::assertNothingSent();
});

test('staff an owner creates with a temp password sign in without a code', function () {
    $owner = User::factory()->owner()->create();
    $role = Role::query()->where('property_id', $owner->property_id)->where('default_role', DefaultRoleEnum::FrontDesk)->firstOrFail();
    $staff = app(CreateStaffWithTemporaryPassword::class)($owner->property, $owner, 'Siti', 'siti@example.test', $role);

    expect($staff->email_device_check)->toBeFalse();

    $this->post(route('login.store'), ['email' => 'siti@example.test', 'password' => $staff->temporary_password]);

    $this->assertAuthenticatedAs($staff);
    Notification::assertNothingSent();
});

test('a wrong password never sends a code', function () {
    $this->post(route('login.store'), ['email' => $this->staff->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    expect(EmailOtp::query()->exists())->toBeFalse();
});
