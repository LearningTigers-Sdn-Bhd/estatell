<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The value `auth.session` keeps in a session for the user's current password hash.
 */
function sessionPasswordHash(User $user): string
{
    return Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());
}

test('another session ends after a password change', function () {
    $user = User::factory()->create();
    $otherSessionHash = sessionPasswordHash($user);

    $user->update(['password' => 'new-password']);

    $this->actingAs($user)
        ->withSession(['password_hash_web' => $otherSessionHash])
        ->get(route('account.profile.edit'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('the session that changes the password stays signed in', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('account.security.edit'))
        ->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('account.profile.edit'))->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('another session ends after a password reset by email', function () {
    $user = User::factory()->create();
    $otherSessionHash = sessionPasswordHash($user);

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    $this->actingAs($user->refresh())
        ->withSession(['password_hash_web' => $otherSessionHash])
        ->get(route('account.profile.edit'))
        ->assertRedirect(route('login'));
});

test('a remember me login ends after a password change', function () {
    $user = User::factory()->create(['remember_token' => Str::random(60)]);
    $recaller = Auth::guard('web')->getRecallerName();
    $oldCookie = $user->id.'|'.$user->remember_token.'|'.$user->getAuthPassword();

    $this->withCookie($recaller, $oldCookie)
        ->get(route('account.profile.edit'))
        ->assertOk();

    Auth::forgetUser();
    $this->flushSession();
    $user->update(['password' => 'new-password']);

    $this->withCookie($recaller, $oldCookie)
        ->get(route('account.profile.edit'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('log out other devices ends other sessions and keeps this one', function () {
    $user = User::factory()->create();
    $otherSessionHash = sessionPasswordHash($user);

    $this->actingAs($user)
        ->from(route('account.security.edit'))
        ->delete(route('account.sessions.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.security.edit'));

    $this->get(route('account.profile.edit'))->assertOk();

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();

    $this->flushSession();
    Auth::forgetUser();

    $this->actingAs($user)
        ->withSession(['password_hash_web' => $otherSessionHash])
        ->get(route('account.profile.edit'))
        ->assertRedirect(route('login'));
});

test('log out other devices needs the current password', function () {
    $user = User::factory()->create();
    $passwordHash = $user->password;

    $this->actingAs($user)
        ->from(route('account.security.edit'))
        ->delete(route('account.sessions.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('account.security.edit'));

    expect($user->refresh()->password)->toBe($passwordHash);
});

test('guests cannot log out other devices', function () {
    $this->delete(route('account.sessions.destroy'), ['password' => 'password'])
        ->assertRedirect(route('login'));
});
