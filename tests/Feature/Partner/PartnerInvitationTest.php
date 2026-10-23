<?php

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\User;
use App\Notifications\PartnerInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function sentPartnerInvitationToken(string $email): string
{
    $token = null;

    Notification::assertSentOnDemand(PartnerInvitationNotification::class, function (PartnerInvitationNotification $notification, array $channels, object $notifiable) use ($email, &$token): bool {
        $token = $notification->token;

        return $notifiable->routes['mail'] === $email;
    });

    return $token;
}

/**
 * The plain token for a closed or missing link. Only the closed states create a row.
 */
function closedPartnerLinkToken(string $state): string
{
    $token = 'closed-link-token';

    if ($state !== 'unknown') {
        PartnerInvitation::factory()->withToken($token)->{$state}()->create();
    }

    return $token;
}

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
    $this->invitation = [
        'partner_name' => 'Andaman Stays',
        'code_prefix' => 'as',
        'email' => ' Ops@Andaman.test ',
    ];
});

test('invite reserves the prefix, emails the link and logs the partner', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.invitations.store'), $this->invitation)
        ->assertRedirect(route('admin.partners.invited'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation sent to ops@andaman.test.']);

    $partner = Partner::query()->sole();
    $invitation = PartnerInvitation::query()->sole();
    $token = sentPartnerInvitationToken('ops@andaman.test');

    expect($partner->status)->toBe(PartnerStatus::Invited)
        ->and($partner->name)->toBe('Andaman Stays')
        ->and($partner->code_prefix)->toBe('AS')
        ->and(DB::table('property_code_counters')->where('prefix', 'AS')->value('last_number'))->toBe(1100)
        ->and($invitation->partner_id)->toBe($partner->id)
        ->and($invitation->email)->toBe('ops@andaman.test')
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->invited_by)->toBe($this->admin->id)
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['event' => 'partner.invited', 'causer_id' => $this->admin->id, 'subject_id' => $partner->id]);
});

test('invite refuses a taken or reserved prefix and an email with an account or an open invitation', function (array $changes, string $field) {
    Partner::factory()->create(['code_prefix' => 'KP']);
    User::factory()->create(['email' => 'taken@example.test']);
    PartnerInvitation::factory()->create(['email' => 'open@example.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.invitations.store'), [...$this->invitation, ...$changes])
        ->assertSessionHasErrors($field);

    expect(Partner::query()->where('name', 'Andaman Stays')->exists())->toBeFalse()
        ->and(PartnerInvitation::query()->count())->toBe(1);
    Notification::assertNothingSent();
})->with([
    'reserved ES' => [['code_prefix' => 'ES'], 'code_prefix'],
    'taken by a partner' => [['code_prefix' => 'kp'], 'code_prefix'],
    'email with an account' => [['email' => 'Taken@Example.test'], 'email'],
    'email with an open invitation' => [['email' => 'Open@Example.test'], 'email'],
]);

test('revoke deletes the invited partner, frees its prefix and removes the invitation', function () {
    $invitation = PartnerInvitation::factory()->create(['invited_by' => $this->admin->id]);
    $partnerId = $invitation->partner_id;
    $prefix = $invitation->partner->code_prefix;

    $this->actingAs($this->admin)
        ->delete(route('admin.partners.invitations.destroy', $invitation))
        ->assertRedirect(route('admin.partners.invited'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Invitation to {$invitation->email} revoked."]);

    expect(Partner::query()->whereKey($partnerId)->exists())->toBeFalse()
        ->and(PartnerInvitation::query()->count())->toBe(0)
        ->and(DB::table('property_code_counters')->where('prefix', $prefix)->exists())->toBeFalse();

    $this->assertDatabaseHas('activity_log', ['event' => 'partner.invitation-revoked', 'causer_id' => $this->admin->id, 'subject_id' => $partnerId]);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.invitations.store'), [...$this->invitation, 'code_prefix' => $prefix])
        ->assertSessionHasNoErrors();
});

test('closed invitations cannot be resent or revoked and log nothing', function (string $state, PartnerStatus $partnerStatus) {
    $partner = Partner::factory()->create(['status' => $partnerStatus]);
    $invitation = PartnerInvitation::factory()->for($partner)->{$state}()->create();
    $before = [$invitation->token_hash, $invitation->expires_at->timestamp];

    $this->actingAs($this->admin)
        ->post(route('admin.partners.invitations.resend', $invitation))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This invitation is no longer open.']);

    $this->actingAs($this->admin)
        ->delete(route('admin.partners.invitations.destroy', $invitation))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This invitation is no longer open.']);

    expect(Partner::query()->whereKey($partner->id)->exists())->toBeTrue()
        ->and([$invitation->fresh()->token_hash, $invitation->fresh()->expires_at->timestamp])->toBe($before);
    expect(Activity::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'accepted' => ['accepted', PartnerStatus::Active],
    'revoked' => ['revoked', PartnerStatus::Invited],
]);

test('resend replaces the link and extends the expiry', function () {
    $invitation = PartnerInvitation::factory()->withToken('old-token')->create([
        'expires_at' => now()->addDay(),
        'last_sent_at' => now()->subDays(3),
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.invitations.resend', $invitation))
        ->assertRedirect(route('admin.partners.invited'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Invitation resent to {$invitation->email}."]);

    $token = sentPartnerInvitationToken($invitation->email);
    $invitation->refresh();

    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and($invitation->last_sent_at->isToday())->toBeTrue();

    $this->assertDatabaseHas('activity_log', ['event' => 'partner.invitation-resent', 'subject_id' => $invitation->partner_id]);

    auth()->logout();

    $this->get(route('partner-invitations.show', 'old-token'))
        ->assertInertia(fn (Assert $page) => $page->component('partner-invitations/accept')->where('invitation', null));
});

test('accept makes the partner Active, creates a verified login and signs them in to the portal', function () {
    $invitation = PartnerInvitation::factory()->withToken('plain-token')->create(['email' => 'ops@andaman.test']);
    $partner = $invitation->partner;

    $this->get(route('partner-invitations.show', 'plain-token'))
        ->assertInertia(fn (Assert $page) => $page->component('partner-invitations/accept')
            ->where('token', 'plain-token')
            ->where('invitation', ['email' => 'ops@andaman.test', 'partnerName' => $partner->name]));

    $this->post(route('partner-invitations.accept', 'plain-token'), [
        'name' => 'Aina Rahman', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('home.redirect'));

    $user = User::query()->where('email', 'ops@andaman.test')->sole();

    expect($user->partner_id)->toBe($partner->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($partner->refresh()->status)->toBe(PartnerStatus::Active)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('activity_log', ['event' => 'partner.invitation-accepted', 'causer_id' => $user->id, 'subject_id' => $partner->id]);

    $this->get(route('home.redirect'))->assertRedirect(route('partner.properties.index'));
});

test('unknown, expired, revoked and accepted links show the notice and create no user', function (string $state) {
    $token = closedPartnerLinkToken($state);

    $this->get(route('partner-invitations.show', $token))
        ->assertInertia(fn (Assert $page) => $page->component('partner-invitations/accept')->where('invitation', null));

    $this->post(route('partner-invitations.accept', $token), [
        'name' => 'Aina Rahman', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('partner-invitations.show', $token));

    expect(User::query()->where('name', 'Aina Rahman')->exists())->toBeFalse();
})->with(['unknown', 'expired', 'revoked', 'accepted']);

test('accept fails when the email got an account after the invitation was sent', function () {
    $invitation = PartnerInvitation::factory()->withToken('plain-token')->create(['email' => 'late@example.test']);
    User::factory()->create(['email' => 'late@example.test']);

    $this->post(route('partner-invitations.accept', 'plain-token'), [
        'name' => 'Aina Rahman', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('partner-invitations.show', 'plain-token'));

    expect($invitation->partner->refresh()->status)->toBe(PartnerStatus::Invited)
        ->and($invitation->fresh()->accepted_at)->toBeNull()
        ->and(User::query()->where('name', 'Aina Rahman')->exists())->toBeFalse();
    $this->assertGuest();
});

test('the Invited tab lists only open invitations and the Active tab leaves out invited partners', function () {
    PartnerInvitation::factory()->create(['email' => 'open@example.test']);
    PartnerInvitation::factory()->accepted()->create();
    PartnerInvitation::factory()->revoked()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.partners.invited'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/partners/index')
            ->where('tab', 'invited')
            ->has('invitations.data', 1)
            ->where('invitations.data.0.email', 'open@example.test'));

    $this->actingAs($this->admin)
        ->get(route('admin.partners.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/partners/index')->has('partners.data', 0));
});

test('non-admins get 404 on every admin invitation route', function (string $method, string $name) {
    $invitation = PartnerInvitation::factory()->create();
    $url = in_array($name, ['admin.partners.invited', 'admin.partners.invitations.store'], true)
        ? route($name)
        : route($name, $invitation);

    $this->actingAs(User::factory()->partner()->create())
        ->call($method, $url, $this->invitation)
        ->assertNotFound();
})->with([
    ['GET', 'admin.partners.invited'],
    ['POST', 'admin.partners.invitations.store'],
    ['POST', 'admin.partners.invitations.resend'],
    ['DELETE', 'admin.partners.invitations.destroy'],
]);
