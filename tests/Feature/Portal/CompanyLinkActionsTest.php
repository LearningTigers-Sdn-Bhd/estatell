<?php

use App\Actions\CityLedger\AcceptCompanyLink;
use App\Actions\CityLedger\SetCompanyPortalLogin;
use App\Enums\CompanyLinkRefusal;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserStatus;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use App\Notifications\CompanyLinkInvitation;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PortalSetup.php';

beforeEach(function () {
    $this->user = portalUser();
    $this->property = Property::factory()->create();
    [$this->company, $this->link] = portalLinkFor($this->user, $this->property, 'pending');
    $this->accept = fn () => $this->actingAs($this->user)->post(route('portal.links.accept', $this->link->id));
    $this->decline = fn () => $this->actingAs($this->user)->post(route('portal.links.decline', $this->link->id));
});

test('accepting activates the link, logs it and marks the invitation read', function () {
    $this->user->notify(new CompanyLinkInvitation($this->link->id, $this->property->name, $this->company->name));

    ($this->accept)()
        ->assertRedirect(route('portal.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Borneo Travel linked.']);

    $link = $this->link->refresh();
    expect($link->status)->toBe(CompanyUserStatus::Active)
        ->and($link->accepted_at)->not->toBeNull()
        ->and($this->user->unreadNotifications()->count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(1);

    $activity = Activity::query()->where('event', 'company-user.link-accepted')->sole();
    expect($activity->causer_id)->toBe($this->user->id)
        ->and($activity->subject_id)->toBe($this->company->id);
});

test('accepting is refused, and writes nothing, for a Suspended company, a property that is not Live and a link that is not Pending', function (string $case, string $message) {
    match ($case) {
        'suspended' => $this->company->forceFill(['status' => CompanyStatus::Suspended])->save(),
        'not live' => $this->property->forceFill(['status' => 'onboarding'])->save(),
        'accepted' => $this->link->forceFill(['status' => CompanyUserStatus::Active, 'accepted_at' => now()])->save(),
        'revoked' => $this->link->forceFill(['status' => CompanyUserStatus::Revoked, 'revoked_at' => now()])->save(),
    };
    $before = $this->link->refresh()->only(['status', 'accepted_at']);

    ($this->accept)()->assertInertiaFlash('toast', ['type' => 'error', 'message' => $message]);

    expect($this->link->refresh()->only(['status', 'accepted_at']))->toEqual($before)
        ->and(Activity::query()->where('event', 'company-user.link-accepted')->count())->toBe(0);
})->with([
    ['suspended', 'This company is suspended. Ask the property to reactivate it.'],
    ['not live', 'This property is not open yet.'],
    ['accepted', 'This invitation is no longer open.'],
    ['revoked', 'This invitation is no longer open.'],
]);

test('a deactivated login cannot accept', function () {
    $this->user->forceFill(['deactivated_at' => now()])->save();

    expect(app(AcceptCompanyLink::class)($this->user, $this->link))->toBe(CompanyLinkRefusal::LoginNotAvailable)
        ->and($this->link->refresh()->status)->toBe(CompanyUserStatus::Pending);
});

test('someone else cannot accept or decline a link', function () {
    $stranger = portalUser();

    $this->actingAs($stranger)->post(route('portal.links.accept', $this->link->id))->assertNotFound();
    $this->actingAs($stranger)->post(route('portal.links.decline', $this->link->id))->assertNotFound();

    expect($this->link->refresh()->status)->toBe(CompanyUserStatus::Pending);
});

test('declining revokes the link, logs it and lets the property set the email again', function () {
    $this->user->notify(new CompanyLinkInvitation($this->link->id, $this->property->name, $this->company->name));

    ($this->decline)()
        ->assertRedirect(route('portal.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation declined.']);

    expect($this->link->refresh()->status)->toBe(CompanyUserStatus::Revoked)
        ->and($this->link->revoked_at)->not->toBeNull()
        ->and($this->user->unreadNotifications()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'company-user.link-declined')->count())->toBe(1);

    $owner = User::factory()->owner()->for($this->property)->create();
    $result = app(SetCompanyPortalLogin::class)($owner, Company::query()->findOrFail($this->company->id), $this->user->email);

    expect($result->isRefused())->toBeFalse();
});

test('declining a link that is not Pending does nothing', function () {
    $this->link->forceFill(['status' => CompanyUserStatus::Active, 'accepted_at' => now()])->save();

    ($this->decline)()->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This invitation is no longer open.']);

    expect($this->link->refresh()->status)->toBe(CompanyUserStatus::Active)
        ->and(Activity::query()->where('event', 'company-user.link-declined')->count())->toBe(0);
});
