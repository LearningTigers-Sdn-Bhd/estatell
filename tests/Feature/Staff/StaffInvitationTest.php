<?php

use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function propertyRole(Property $property, DefaultRoleEnum $defaultRole): Role
{
    return Role::query()->where('property_id', $property->id)->where('default_role', $defaultRole)->firstOrFail();
}

function sentInvitationToken(string $email): string
{
    $token = null;

    Notification::assertSentOnDemand(StaffInvitationNotification::class, function (StaffInvitationNotification $notification, array $channels, object $notifiable) use ($email, &$token): bool {
        $token = $notification->token;

        return $notifiable->routes['mail'] === $email;
    });

    return $token;
}

beforeEach(fn () => Notification::fake());

test('owners invite staff and the emailed link opens the accept page', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('settings.staff.invitations.store', $property->code), [
            'email' => ' Mei@Example.com ',
            'role_id' => propertyRole($property, DefaultRoleEnum::FrontDesk)->id,
        ])
        ->assertRedirect(route('settings.staff.invited', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation sent to mei@example.com.']);

    $invitation = StaffInvitation::query()->sole();
    $token = sentInvitationToken('mei@example.com');

    expect($invitation->email)->toBe('mei@example.com')
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->invited_by)->toBe($owner->id)
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and(Activity::query()->sole()->event)->toBe('staff.invited');

    auth()->logout();

    $this->get(route('invitations.show', $token))
        ->assertInertia(fn (Assert $page) => $page->component('invitations/accept')
            ->where('invitation', ['email' => 'mei@example.com', 'propertyName' => $property->name, 'role' => 'Front desk']));
});

test('invitations are refused for existing accounts, open invitations and roles the inviter cannot give', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = $manager->property;
    $existing = User::factory()->create();
    StaffInvitation::factory()->for($property)->create(['email' => 'open@example.com']);
    $otherRole = propertyRole(tap(Property::factory()->create(), fn ($other) => app(SeedDefaultRoles::class)($other)), DefaultRoleEnum::FrontDesk);
    $store = route('settings.staff.invitations.store', $property->code);

    $this->actingAs($manager)->post($store, ['email' => strtoupper($existing->email), 'role_id' => propertyRole($property, DefaultRoleEnum::FrontDesk)->id])
        ->assertSessionHasErrors('email');
    $this->post($store, ['email' => 'open@example.com', 'role_id' => propertyRole($property, DefaultRoleEnum::FrontDesk)->id])
        ->assertSessionHasErrors('email');
    $this->post($store, ['email' => 'new@example.com', 'role_id' => propertyRole($property, DefaultRoleEnum::Owner)->id])
        ->assertSessionHasErrors('role_id');
    $this->post($store, ['email' => 'new@example.com', 'role_id' => $otherRole->id])
        ->assertSessionHasErrors('role_id');

    expect(StaffInvitation::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('the staff page needs staff.view and inviting needs staff.manage', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $property = $frontDesk->property;

    $this->actingAs($frontDesk)->get(route('settings.staff.index', $property->code))->assertForbidden();
    $this->post(route('settings.staff.invitations.store', $property->code), [
        'email' => 'new@example.com',
        'role_id' => propertyRole($property, DefaultRoleEnum::FrontDesk)->id,
    ])->assertForbidden();

    $other = User::factory()->owner()->create();
    $this->actingAs($other)->get(route('settings.staff.index', $property->code))->assertNotFound();
});

test('the staff page lists staff with roles and only open invitations', function () {
    $owner = User::factory()->owner()->create(['name' => 'Aina']);
    $property = $owner->property;
    User::factory()->for($property)->create(['name' => 'Ben']);
    $open = StaffInvitation::factory()->for($property)->expired()->create();
    StaffInvitation::factory()->for($property)->accepted()->create();
    StaffInvitation::factory()->for($property)->revoked()->create();

    $this->actingAs($owner)
        ->get(route('settings.staff.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/staff/index')
            ->where('tab', 'active')
            ->where('counts', ['active' => 2, 'invited' => 1])
            ->where('canManage', true)
            ->has('roles', 5)
            ->where('staff.0.name', 'Aina')
            ->where('staff.0.role', 'Owner')
            ->where('staff.0.isYou', true)
            ->where('staff.1.name', 'Ben')
            ->where('staff.1.role', null)
            ->missing('invitations'));

    $this->get(route('settings.staff.invited', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'invited')
            ->has('invitations', 1)
            ->where('invitations.0.id', $open->id)
            ->where('invitations.0.expired', true)
            ->missing('staff'));
});

test('managers do not get the owner role in the invite form', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();

    $this->actingAs($manager)
        ->get(route('settings.staff.index', $manager->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('roles', 4)
            ->where('roles.0.label', 'Manager'));
});

test('resending replaces the link and extends the expiry', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $invitation = StaffInvitation::factory()->for($property)->withToken('old-token')->expired()->create();

    $this->actingAs($owner)
        ->post(route('settings.staff.invitations.resend', [$property->code, $invitation->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Invitation resent to {$invitation->email}."]);

    $token = sentInvitationToken($invitation->email);

    expect($invitation->refresh()->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and(Activity::query()->sole()->event)->toBe('staff.invitation-resent');

    auth()->logout();
    $this->get(route('invitations.show', 'old-token'))->assertInertia(fn (Assert $page) => $page->where('invitation', null));
});

test('revoking closes the invitation and frees the email', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $invitation = StaffInvitation::factory()->for($property)->withToken('revoked-token')->create(['email' => 'mei@example.com']);

    $this->actingAs($owner)
        ->delete(route('settings.staff.invitations.destroy', [$property->code, $invitation->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation to mei@example.com revoked.']);

    expect($invitation->refresh()->revoked_at)->not->toBeNull()
        ->and(Activity::query()->sole()->event)->toBe('staff.invitation-revoked');

    $this->post(route('settings.staff.invitations.store', $property->code), [
        'email' => 'mei@example.com',
        'role_id' => propertyRole($property, DefaultRoleEnum::FrontDesk)->id,
    ])->assertSessionHasNoErrors();
});

test('closed invitations cannot be resent or revoked', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $accepted = StaffInvitation::factory()->for($property)->accepted()->create();

    $this->actingAs($owner)
        ->post(route('settings.staff.invitations.resend', [$property->code, $accepted->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This invitation is no longer open.']);
    $this->delete(route('settings.staff.invitations.destroy', [$property->code, $accepted->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This invitation is no longer open.']);

    expect(Activity::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('another property\'s invitation returns 404', function () {
    $owner = User::factory()->owner()->create();
    $foreign = StaffInvitation::factory()->create();

    $this->actingAs($owner)
        ->delete(route('settings.staff.invitations.destroy', [$owner->property->code, $foreign->id]))
        ->assertNotFound();

    expect($foreign->refresh()->revoked_at)->toBeNull();
});

test('accepting creates a verified staff user with the invited role and signs them in', function () {
    $invitation = StaffInvitation::factory()->withToken('good-token')->create(['email' => 'mei@example.com']);

    $this->post(route('invitations.accept', 'good-token'), [
        'name' => 'Mei Ling',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('home.redirect'));

    $user = User::query()->where('email', 'mei@example.com')->sole();
    setPermissionsTeamId($invitation->property_id);

    expect(auth()->id())->toBe($user->id)
        ->and($user->property_id)->toBe($invitation->property_id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->roles->pluck('id')->all())->toBe([$invitation->role_id])
        ->and($invitation->refresh()->accepted_at)->not->toBeNull()
        ->and(Activity::query()->sole()->event)->toBe('staff.invitation-accepted');
});

test('unknown, expired, revoked and accepted links show a notice and create no user', function (Closure $makeInvitation) {
    $makeInvitation();

    $this->get(route('invitations.show', 'the-token'))
        ->assertInertia(fn (Assert $page) => $page->component('invitations/accept')->where('invitation', null));

    $this->post(route('invitations.accept', 'the-token'), [
        'name' => 'Mei Ling', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('invitations.show', 'the-token'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This invitation link is no longer valid.']);

    expect(User::query()->count())->toBe(0);
})->with([
    'unknown' => fn () => fn () => null,
    'expired' => fn () => fn () => StaffInvitation::factory()->withToken('the-token')->expired()->create(),
    'revoked' => fn () => fn () => StaffInvitation::factory()->withToken('the-token')->revoked()->create(),
    'accepted' => fn () => fn () => StaffInvitation::factory()->withToken('the-token')->accepted()->create(),
]);

test('accepting fails when the email got an account after the invitation was sent', function () {
    $invitation = StaffInvitation::factory()->withToken('late-token')->create(['email' => 'mei@example.com']);
    User::factory()->create(['email' => 'Mei@example.com']);

    $this->post(route('invitations.accept', 'late-token'), [
        'name' => 'Mei Ling', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This invitation link is no longer valid.']);

    expect($invitation->refresh()->accepted_at)->toBeNull()
        ->and(User::query()->count())->toBe(1);
});
