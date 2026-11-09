<?php

use App\Enums\OnboardingStep;
use App\Enums\OtaCredentialStatus;
use App\Enums\StepState;
use App\Models\BookingSource;
use App\Models\OnboardingSkip;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use App\Models\User;
use App\Services\Onboarding\Checks\ChannelManagerCheck;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    $this->agoda = OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda']);
    $this->booking = OtaChannel::factory()->create(['code' => 'booking_com', 'name' => 'Booking.com']);
    $this->other = OtaChannel::factory()->other()->create();
    $this->row = fn (array $overrides = []): array => [
        'ota_channel_id' => (string) $this->agoda->id,
        'channel_name' => '',
        'property_code' => '123456',
        'username' => 'frontdesk@seaview.my',
        'password' => 'agoda-secret',
        'market_manager_name' => '',
        'market_manager_phone' => '',
        'market_manager_email' => '',
        ...$overrides,
    ];
    $this->save = fn (array $rows, array $savedIds = [], ?User $user = null) => $this->actingAs($user ?? $this->owner)
        ->put(route('onboarding.channel-manager.update', $this->property->code), ['saved_ids' => $savedIds, 'rows' => $rows]);
    $this->progress = fn (): OnboardingProgress => app(OnboardingProgress::class);
});

test('the username and password are encrypted at rest and read back in plain text', function () {
    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    $stored = DB::table('property_ota_credentials')->where('property_id', $this->property->id)->first();
    $credential = PropertyOtaCredential::query()->where('property_id', $this->property->id)->firstOrFail();

    expect($stored->username)->not->toBe('frontdesk@seaview.my')
        ->and($stored->password)->not->toBe('agoda-secret')
        ->and($credential->username)->toBe('frontdesk@seaview.my')
        ->and($credential->password)->toBe('agoda-secret')
        ->and($credential->status)->toBe(OtaCredentialStatus::Pending)
        ->and($credential->toArray())->not->toHaveKey('password');
});

test('a saved row stays exactly as it was when it is kept', function () {
    ($this->save)([($this->row)()]);
    $saved = PropertyOtaCredential::query()->where('property_id', $this->property->id)->sole();

    ($this->save)([], [$saved->id])->assertSessionHasNoErrors();

    expect($saved->refresh()->username)->toBe('frontdesk@seaview.my')
        ->and($saved->password)->toBe('agoda-secret');
});

test('a new row without a password is rejected and nothing is saved', function () {
    ($this->save)([($this->row)(['password' => ''])])
        ->assertSessionHasErrors(['rows.0.password']);

    expect(PropertyOtaCredential::query()->count())->toBe(0);
});

test('a channel can appear only once per property', function () {
    ($this->save)([($this->row)(), ($this->row)(['password' => 'other-secret'])])
        ->assertSessionHasErrors(['rows.1.ota_channel_id' => 'This channel is already in the list.']);

    expect(PropertyOtaCredential::query()->count())->toBe(0);
});

test('Other needs a channel name, and one Other row per name ignoring case', function () {
    ($this->save)([($this->row)(['ota_channel_id' => (string) $this->other->id])])
        ->assertSessionHasErrors(['rows.0.channel_name']);

    ($this->save)([
        ($this->row)(['ota_channel_id' => (string) $this->other->id, 'channel_name' => 'Traveloka']),
        ($this->row)(['ota_channel_id' => (string) $this->other->id, 'channel_name' => 'traveloka']),
    ])->assertSessionHasErrors(['rows.1.channel_name' => 'This channel name is already in the list.']);

    ($this->save)([
        ($this->row)(['ota_channel_id' => (string) $this->other->id, 'channel_name' => 'Traveloka']),
        ($this->row)(['ota_channel_id' => (string) $this->other->id, 'channel_name' => 'Trip.com']),
    ])->assertSessionHasNoErrors();

    expect(PropertyOtaCredential::query()->where('channel_name', 'Trip.com')->exists())->toBeTrue();
});

test('an inactive channel is refused for a new row but a saved row can stay', function () {
    $this->agoda->update(['is_active' => false]);

    ($this->save)([($this->row)()])->assertSessionHasErrors(['rows.0.ota_channel_id']);

    $saved = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id]);

    ($this->save)([], [$saved->id])->assertSessionHasNoErrors();

    expect($saved->refresh()->ota_channel_id)->toBe($this->agoda->id);
});

test('a new row cannot repeat a saved channel that stays, but can replace a removed one', function () {
    $saved = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id, 'password' => 'old-secret']);

    ($this->save)([($this->row)()], [$saved->id])
        ->assertSessionHasErrors(['rows.0.ota_channel_id' => 'This channel is already in the list.']);

    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    expect(PropertyOtaCredential::query()->where('property_id', $this->property->id)->sole()->password)->toBe('agoda-secret');
});

test('a row of another property cannot be kept or listed', function () {
    $foreign = PropertyOtaCredential::factory()->create(['ota_channel_id' => $this->agoda->id]);

    ($this->save)([], [$foreign->id])->assertSessionHasErrors(['saved_ids.0']);

    expect($foreign->fresh())->not->toBeNull();

    $this->actingAs($this->owner)
        ->get(route('onboarding.channel-manager.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->has('savedRows', 0));
});

test('saved rows are removed when they are missing from the submit', function () {
    $kept = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id]);
    PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->booking->id]);

    ($this->save)([], [$kept->id])->assertSessionHasNoErrors();

    expect(PropertyOtaCredential::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('the edit page sends only the channel of a saved row, never its login', function () {
    PropertyOtaCredential::factory()->for($this->property)->create([
        'ota_channel_id' => $this->agoda->id,
        'property_code' => 'AG-99887',
        'username' => 'frontdesk@seaview.my',
        'password' => 'agoda-secret',
        'market_manager_name' => 'Aisha Manager',
    ]);
    $this->booking->update(['is_active' => false]);

    $this->actingAs($this->owner)
        ->get(route('onboarding.channel-manager.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/channel-manager')
            ->has('savedRows', 1)
            ->has('savedRows.0', 2)
            ->has('savedRows.0.id')
            ->where('savedRows.0.channelLabel', 'Agoda')
            ->has('channels', 2)
            ->where('channels.0.name', 'Agoda')
            ->where('channels.1.name', 'Other')
            ->where('channels.1.isOther', true)
            ->where('locked', false))
        ->assertDontSee('agoda-secret')
        ->assertDontSee('frontdesk@seaview.my')
        ->assertDontSee('AG-99887')
        ->assertDontSee('Aisha Manager');
});

test('the step is a locked preview before basics is done', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('onboarding.channel-manager.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/channel-manager')
            ->where('locked', true)
            ->where('blockedBy.label', 'Basics'));

    $this->actingAs($owner)
        ->put(route('onboarding.channel-manager.update', $property->code), ['saved_ids' => [], 'rows' => [($this->row)()]])
        ->assertSessionHasErrors(['onboarding']);

    expect(PropertyOtaCredential::query()->count())->toBe(0);
});

test('an empty save counts as skipped and saving a row removes the skip', function () {
    ($this->save)([])->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::ChannelManager)->exists())->toBeTrue()
        ->and(($this->progress)()->state($this->property, OnboardingStep::ChannelManager))->toBe(StepState::Done);

    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::ChannelManager)->exists())->toBeFalse()
        ->and(($this->progress)()->state($this->property, OnboardingStep::ChannelManager))->toBe(StepState::Done);
});

test('keeping a saved row is not a skip', function () {
    $saved = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id]);

    ($this->save)([], [$saved->id])->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->exists())->toBeFalse();
});

test('the check is done with at least one row and not started without one', function () {
    expect((new ChannelManagerCheck)->state($this->property))->toBe(StepState::NotStarted);

    PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id]);

    expect((new ChannelManagerCheck)->state($this->property))->toBe(StepState::Done);
});

test('the channel manager step has its own page and is optional', function () {
    expect(OnboardingStep::ChannelManager->routeName())->toBe('onboarding.channel-manager.edit')
        ->and(OnboardingStep::ChannelManager->isRequired())->toBeFalse()
        ->and(OnboardingStep::ChannelManager->phase()->value)->toBe('channels');
});

test('saving a row creates its OTA booking source', function () {
    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    expect(BookingSource::query()->where('property_id', $this->property->id)->sole())
        ->name->toBe('Agoda')
        ->ota_channel_id->toBe($this->agoda->id)
        ->badge_letters->toBe('A');
});

test('an Other row creates its source named by the typed channel name', function () {
    ($this->save)([($this->row)(['ota_channel_id' => (string) $this->other->id, 'channel_name' => 'Traveloka'])])
        ->assertSessionHasNoErrors();

    expect(BookingSource::query()->where('property_id', $this->property->id)->sole())
        ->name->toBe('Traveloka')
        ->ota_channel_id->toBe($this->other->id);
});

test('a repeat save with the same row adds no source', function () {
    ($this->save)([($this->row)()])->assertSessionHasNoErrors();
    $saved = PropertyOtaCredential::query()->where('property_id', $this->property->id)->sole();

    ($this->save)([], [$saved->id])->assertSessionHasNoErrors();

    expect(BookingSource::query()->where('property_id', $this->property->id)->count())->toBe(1);
});

test('removing a row keeps its source', function () {
    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    ($this->save)([])->assertSessionHasNoErrors();

    expect(PropertyOtaCredential::query()->count())->toBe(0)
        ->and(BookingSource::query()->where('property_id', $this->property->id)->where('name', 'Agoda')->exists())->toBeTrue();
});

test('a name taken by a direct source gets " (OTA)" on the saved row', function () {
    BookingSource::factory()->for($this->property)->create(['name' => 'Agoda']);

    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    expect(BookingSource::query()->where('property_id', $this->property->id)->where('type', 'ota')->sole()->name)->toBe('Agoda (OTA)');
});

test('a refused save creates no booking source', function () {
    ($this->save)([($this->row)(), ($this->row)(['password' => 'other-secret'])])
        ->assertSessionHasErrors();

    expect(BookingSource::query()->where('property_id', $this->property->id)->exists())->toBeFalse();
});
