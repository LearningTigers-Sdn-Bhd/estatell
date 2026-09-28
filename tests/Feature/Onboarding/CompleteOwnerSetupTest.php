<?php

use App\Actions\Onboarding\CompleteOwnerSetup;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function ownerSetupUrl(User $owner, string $route, mixed $expiration = null): string
{
    return URL::temporarySignedRoute($route, $expiration ?? now()->addDays(7), ['user' => $owner]);
}

test('a valid setup link shows the owner and supplies an exact signed post url', function () {
    $owner = User::factory()->unverified()->owner()->create(['name' => 'Aina', 'email' => 'aina@example.com']);
    $showUrl = ownerSetupUrl($owner, 'guest.owner-setup.show');

    $this->get($showUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/owner-setup')
            ->where('owner.name', 'Aina')
            ->where('owner.email', 'aina@example.com')
            ->where('owner.propertyName', $owner->property->name)
            ->where('actionUrl', fn (string $url) => str_contains($url, '/g/owners/'.$owner->id.'/setup')
                && str_contains($url, 'signature='))
            ->has('passwordRules'));
});

test('completing setup changes the password verifies and signs in the owner once', function () {
    $owner = User::factory()->unverified()->owner()->create();
    $storeUrl = ownerSetupUrl($owner, 'guest.owner-setup.store');

    $this->post($storeUrl, [
        'password' => 'new-owner-password',
        'password_confirmation' => 'new-owner-password',
    ])->assertRedirect(route('home.redirect'));

    expect(auth()->id())->toBe($owner->id)
        ->and($owner->refresh()->email_verified_at)->not->toBeNull()
        ->and(Hash::check('new-owner-password', $owner->password))->toBeTrue()
        ->and(Activity::query()->sole()->event)->toBe('owner.account-setup-completed');

    auth()->logout();
    $this->post($storeUrl, [
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertRedirect(route('login'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This setup link is no longer available.']);

    expect(Activity::query()->count())->toBe(1)
        ->and(Hash::check('new-owner-password', $owner->fresh()->password))->toBeTrue();
});

test('the action refuses an already verified owner', function () {
    $owner = User::factory()->owner()->create();
    $password = $owner->password;

    expect(app(CompleteOwnerSetup::class)($owner, 'replacement'))->toBeFalse()
        ->and($owner->fresh()->password)->toBe($password)
        ->and(Activity::query()->count())->toBe(0);
});

test('expired and tampered setup links return the invalid signature response', function () {
    $owner = User::factory()->unverified()->owner()->create();

    $this->get(ownerSetupUrl($owner, 'guest.owner-setup.show', now()->subMinute()))->assertForbidden();

    $valid = ownerSetupUrl($owner, 'guest.owner-setup.show');
    $this->get($valid.'&extra=changed')->assertForbidden();
});

test('authenticated users cannot open the guest setup page', function (bool $admin) {
    $owner = User::factory()->unverified()->owner()->create();
    $user = $admin ? User::factory()->admin()->create() : User::factory()->create();

    $this->actingAs($user)
        ->get(ownerSetupUrl($owner, 'guest.owner-setup.show'))
        ->assertRedirect($admin ? route('admin.dashboard') : route('home.redirect'));
})->with([
    'staff' => false,
    'admin' => true,
]);

test('the setup post is throttled and validates the shared password rules', function () {
    $owner = User::factory()->unverified()->owner()->create();
    $url = ownerSetupUrl($owner, 'guest.owner-setup.store');

    foreach (range(1, 6) as $attempt) {
        $this->post($url, ['password' => ''])->assertSessionHasErrors('password');
    }

    $this->post($url, ['password' => ''])->assertTooManyRequests();
    expect($owner->fresh()->email_verified_at)->toBeNull();
});
