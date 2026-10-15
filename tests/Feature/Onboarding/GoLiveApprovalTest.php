<?php

use App\Actions\Onboarding\ApproveGoLive;
use App\Actions\Onboarding\RejectGoLive;
use App\Actions\Onboarding\RequestGoLive;
use App\Actions\Onboarding\WithdrawGoLiveRequest;
use App\Enums\GoLiveDecision;
use App\Enums\OnboardingStep;
use App\Enums\PropertyStatus;
use App\Models\GoLiveReview;
use App\Models\Partner;
use App\Models\PlatformSetting;
use App\Models\Property;
use App\Models\User;
use App\Notifications\GoLiveApproved;
use App\Notifications\GoLiveRejected;
use App\Notifications\GoLiveRequested;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => true]);
    Cache::forget(PlatformSettings::CACHE_KEY);
});

function ownerWithPendingRequest(?Partner $partner = null): User
{
    $owner = User::factory()->owner()->for(Property::factory()->onboarding()->state(['partner_id' => $partner?->id]))->create();
    completeRequiredOnboardingSteps($owner->property);
    expect(app(RequestGoLive::class)($owner, $owner->property))->toBeTrue();

    return $owner;
}

function approvalAdmin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

test('requesting go live records the request, opens nothing and tells admins and the partner', function () {
    Notification::fake();
    $partner = Partner::factory()->create();
    $admin = User::factory()->admin()->create();
    $partnerUser = User::factory()->partner($partner)->create();
    User::factory()->partner()->create();
    $owner = User::factory()->owner()->for(Property::factory()->onboarding()->state(['partner_id' => $partner->id]))->create();
    completeRequiredOnboardingSteps($owner->property);

    expect(app(RequestGoLive::class)($owner, $owner->property))->toBeTrue();

    $property = $owner->property->refresh();
    $review = GoLiveReview::query()->sole();

    expect($property->status)->toBe(PropertyStatus::PendingApproval)
        ->and($property->went_live_at)->toBeNull()
        ->and($property->businessDates()->exists())->toBeFalse()
        ->and($review->submitted_by)->toBe($owner->id)
        ->and($review->decision)->toBeNull()
        ->and(Activity::query()->where('event', 'property.go-live-requested')->exists())->toBeTrue();

    Notification::assertSentTo([$admin, $partnerUser], GoLiveRequested::class);
    Notification::assertCount(2);
});

test('requesting go live is refused when approval is off, when not ready, or when one is pending', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    expect(app(RequestGoLive::class)($owner, $owner->property))->toBeFalse();

    completeRequiredOnboardingSteps($owner->property);
    expect(app(RequestGoLive::class)($owner, $owner->property))->toBeTrue()
        ->and(app(RequestGoLive::class)($owner, $owner->property))->toBeFalse()
        ->and(GoLiveReview::query()->count())->toBe(1);

    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => false]);
    Cache::forget(PlatformSettings::CACHE_KEY);
    $other = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    completeRequiredOnboardingSteps($other->property);

    expect(app(RequestGoLive::class)($other, $other->property))->toBeFalse();
});

test('withdrawing returns the property to onboarding without a note', function () {
    $owner = ownerWithPendingRequest();

    $this->actingAs($owner)
        ->delete(route('onboarding.go-live.withdraw', $owner->property->code))
        ->assertRedirect(route('onboarding.go-live.show', $owner->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Request withdrawn. You can make changes now.']);

    $review = GoLiveReview::query()->sole();

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::Onboarding)
        ->and($review->decision)->toBe(GoLiveDecision::Withdrawn)
        ->and($review->note)->toBeNull()
        ->and(app(WithdrawGoLiveRequest::class)($owner, $owner->property))->toBeFalse();
});

test('a pending property can still be opened but every write is refused', function () {
    $owner = ownerWithPendingRequest();
    $property = $owner->property;

    $this->actingAs($owner)
        ->get(route('onboarding.basics.edit', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.awaitingApproval', true));

    $this->actingAs($owner)
        ->from(route('onboarding.basics.edit', $property->code))
        ->put(route('onboarding.basics.update', $property->code), basicsInput(['name' => 'Changed name']))
        ->assertRedirect(route('onboarding.basics.edit', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Waiting for approval. Withdraw to make changes.']);

    expect($property->refresh()->name)->not->toBe('Changed name');
});

test('approving opens today, sets the property live, records the decision and tells the owner', function () {
    Notification::fake();
    $owner = ownerWithPendingRequest();
    $admin = approvalAdmin();

    $this->actingAs($admin)
        ->post(route('admin.properties.go-live.approve', $owner->property->code))
        ->assertRedirect(route('admin.properties.show', $owner->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Approved. '.$owner->property->name.' is live.']);

    $property = $owner->property->refresh();
    $review = GoLiveReview::query()->sole();

    expect($property->status)->toBe(PropertyStatus::Live)
        ->and($property->went_live_at)->not->toBeNull()
        ->and($property->businessDates()->sole()->date->toDateString())->toBe('2026-10-10')
        ->and($review->decision)->toBe(GoLiveDecision::Approved)
        ->and($review->decided_by)->toBe($admin->id)
        ->and($review->opening_date->toDateString())->toBe('2026-10-10')
        ->and(Activity::query()->where('event', 'property.go-live-approved')->exists())->toBeTrue();

    Notification::assertSentTo($owner, GoLiveApproved::class);
});

test('approving can open an earlier date but never a later one', function () {
    $owner = ownerWithPendingRequest();
    $admin = approvalAdmin();

    $this->actingAs($admin)
        ->post(route('admin.properties.go-live.approve', $owner->property->code), ['opening_date' => '2026-10-11'])
        ->assertSessionHasErrors('opening_date');

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::PendingApproval);

    $this->actingAs($admin)
        ->post(route('admin.properties.go-live.approve', $owner->property->code), ['opening_date' => '2026-10-01'])
        ->assertRedirect();

    expect($owner->property->refresh()->businessDates()->sole()->date->toDateString())->toBe('2026-10-01')
        ->and(GoLiveReview::query()->sole()->opening_date->toDateString())->toBe('2026-10-01');
});

test('sending back needs a note, returns the property to onboarding and flags the sections', function () {
    Notification::fake();
    $owner = ownerWithPendingRequest();
    $admin = approvalAdmin();
    $route = route('admin.properties.go-live.reject', $owner->property->code);

    $this->actingAs($admin)->post($route, ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($admin)->post($route, ['note' => 'Fix it', 'flags' => ['review']])->assertSessionHasErrors('flags.0');

    $this->actingAs($admin)
        ->post($route, ['note' => 'Please check the room prices.', 'flags' => ['basic_rate', 'taxes']])
        ->assertRedirect(route('admin.properties.show', $owner->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sent back to the owner.']);

    $review = GoLiveReview::query()->sole();

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::Onboarding)
        ->and($owner->property->businessDates()->exists())->toBeFalse()
        ->and($review->decision)->toBe(GoLiveDecision::Rejected)
        ->and($review->note)->toBe('Please check the room prices.')
        ->and($review->flags->map(fn ($flag): string => $flag->step->value)->sort()->values()->all())->toBe(['basic_rate', 'taxes']);

    Notification::assertSentTo($owner, GoLiveRejected::class);
});

test('a sent back request shows the note and flags until the next submit', function () {
    $owner = ownerWithPendingRequest();
    app(RejectGoLive::class)(approvalAdmin(), $owner->property, 'Check the prices.', [OnboardingStep::BasicRate]);

    $this->actingAs($owner)
        ->get(route('onboarding.go-live.show', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sentBack.note', 'Check the prices.')
            ->where('pendingRequest', null)
            ->where('review.phases', fn ($phases) => collect($phases)->flatMap(fn ($phase) => $phase['rows'])
                ->where('value', 'basic_rate')->first()['flagged'] === true));

    $this->actingAs($owner)
        ->get(route('onboarding.basics.edit', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.phases', fn ($phases) => collect($phases)->flatMap(fn ($phase) => $phase['steps'])
            ->where('value', 'basic_rate')->first()['flagged'] === true));

    expect(app(RequestGoLive::class)($owner, $owner->property))->toBeTrue();

    $this->actingAs($owner)
        ->get(route('onboarding.go-live.show', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('sentBack', null)->where('pendingRequest.sentOn', '10 Oct 2026'));

    expect(GoLiveReview::query()->count())->toBe(2);
});

test('decisions are refused when nothing is pending', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    expect(app(ApproveGoLive::class)(approvalAdmin(), $owner->property))->toBeFalse()
        ->and(app(RejectGoLive::class)(approvalAdmin(), $owner->property, 'No'))->toBeFalse();
});

test('only admins reach the review page, and only for a pending property', function () {
    $owner = ownerWithPendingRequest();
    $admin = approvalAdmin();

    $this->actingAs($owner)->get(route('admin.properties.go-live.show', $owner->property->code))->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.properties.go-live.show', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/go-live')
            ->where('goLive.submittedBy', $owner->name)
            ->where('goLive.today', '2026-10-10')
            ->where('goLive.review.ready', true));

    app(WithdrawGoLiveRequest::class)($owner, $owner->property);

    $this->actingAs($admin)
        ->get(route('admin.properties.go-live.show', $owner->property->code))
        ->assertRedirect(route('admin.properties.show', $owner->property->code));
});

test('the admin sidebar counts the properties waiting for approval', function () {
    ownerWithPendingRequest();
    ownerWithPendingRequest();

    $this->actingAs(approvalAdmin())
        ->get(route('admin.properties.index'))
        ->assertInertia(fn (Assert $page) => $page->where('adminNavigation.1.badge', 2));
});

test('support two factor is required to approve or send back', function () {
    config(['estatell.support_require_two_factor' => true]);
    $owner = ownerWithPendingRequest();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.properties.go-live.approve', $owner->property->code))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);
    $this->post(route('admin.properties.go-live.reject', $owner->property->code), ['note' => 'No'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::PendingApproval);
});

test('the waiting tab lists the sent date and warning count of each pending property', function () {
    $owner = ownerWithPendingRequest();
    ownerWithPendingRequest();
    Property::factory()->onboarding()->create();

    $this->actingAs(approvalAdmin())
        ->get(route('admin.properties.index', ['filter' => ['status' => 'pending_approval']]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 2)
            ->where("waiting.{$owner->property->code}.sentOn", '10 Oct 2026')
            ->where("waiting.{$owner->property->code}.warningsCount", fn ($count) => is_int($count)));

    $this->get(route('admin.properties.index'))->assertInertia(fn (Assert $page) => $page->where('waiting', []));
});
