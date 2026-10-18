<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\OnboardingStep;
use App\Enums\OtaCredentialStatus;
use App\Models\BookingSource;
use App\Models\OnboardingSkip;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory())->create();
    $this->property = $this->owner->property;
    $this->agoda = OtaChannel::factory()->create(['code' => 'agoda', 'name' => 'Agoda']);
    $this->booking = OtaChannel::factory()->create(['code' => 'booking_com', 'name' => 'Booking.com']);
    $this->other = OtaChannel::factory()->other()->create();
    $this->row = fn (array $overrides = []): array => [
        'id' => null,
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
    $this->save = fn (array $rows, ?User $user = null) => $this->actingAs($user ?? $this->owner)
        ->put(route('settings.ota-lists.update', $this->property->code), ['rows' => $rows]);
});

test('the owner can view the OTA lists page and save rows', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.ota-lists.edit', $this->property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ota-lists')
            ->has('savedRows', 0)
            ->has('channels', 3)
            ->where('maxRows', 20));

    ($this->save)([($this->row)()])
        ->assertRedirect(route('settings.ota-lists.edit', $this->property->code))
        ->assertSessionHasNoErrors();

    $credential = PropertyOtaCredential::query()->where('property_id', $this->property->id)->firstOrFail();

    expect($credential->ota_channel_id)->toBe($this->agoda->id)
        ->and($credential->username)->toBe('frontdesk@seaview.my')
        ->and($credential->password)->toBe('agoda-secret')
        ->and($credential->status)->toBe(OtaCredentialStatus::Pending);
});

test('only the owner can reach the page and save it', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->for($this->property)->create();
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->for($this->property)->create();

    foreach ([$frontDesk, $manager] as $user) {
        $this->actingAs($user)
            ->get(route('settings.ota-lists.edit', $this->property->code))
            ->assertForbidden();

        ($this->save)([($this->row)()], $user)->assertForbidden();
    }

    expect(PropertyOtaCredential::query()->count())->toBe(0);
});

test('another property\'s rows are not listed or editable', function () {
    $foreign = PropertyOtaCredential::factory()->create(['ota_channel_id' => $this->agoda->id, 'username' => 'someone@else.my']);

    ($this->save)([($this->row)(['id' => $foreign->id, 'username' => 'hijack@seaview.my'])])
        ->assertSessionHasErrors(['rows.0.id']);

    expect($foreign->refresh()->username)->toBe('someone@else.my');

    $this->actingAs($this->owner)
        ->get(route('settings.ota-lists.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->has('savedRows', 0));
});

test('a blank password keeps the saved one and the status', function () {
    $saved = PropertyOtaCredential::factory()->processed()->for($this->property)->create([
        'ota_channel_id' => $this->agoda->id,
        'password' => 'agoda-secret',
    ]);

    ($this->save)([($this->row)(['id' => $saved->id, 'username' => 'owner@seaview.my', 'password' => ''])])
        ->assertSessionHasNoErrors();

    expect($saved->refresh())
        ->username->toBe('owner@seaview.my')
        ->password->toBe('agoda-secret')
        ->status->toBe(OtaCredentialStatus::Processed);
});

test('a new password sets the row back to Pending and logs it without the password', function () {
    $saved = PropertyOtaCredential::factory()->processed()->for($this->property)->create([
        'ota_channel_id' => $this->agoda->id,
    ]);

    ($this->save)([($this->row)(['id' => $saved->id, 'password' => 'new-secret'])])
        ->assertSessionHasNoErrors();

    expect($saved->refresh())
        ->password->toBe('new-secret')
        ->status->toBe(OtaCredentialStatus::Pending);

    $activity = Activity::query()->where('event', 'ota_credential.password-changed')->sole();

    expect($activity->description)->toBe('OTA password changed for Agoda')
        ->and($activity->causer_id)->toBe($this->owner->id)
        ->and($activity->subject_id)->toBe($this->property->id)
        ->and($activity->properties->toJson())->not->toContain('new-secret')
        ->and(Activity::query()->get()->toJson())->not->toContain('new-secret');
});

test('changing other fields keeps the status and logs nothing', function () {
    $saved = PropertyOtaCredential::factory()->processed()->for($this->property)->create([
        'ota_channel_id' => $this->agoda->id,
    ]);

    ($this->save)([($this->row)(['id' => $saved->id, 'property_code' => '999999', 'password' => ''])])
        ->assertSessionHasNoErrors();

    expect($saved->refresh())
        ->property_code->toBe('999999')
        ->status->toBe(OtaCredentialStatus::Processed)
        ->and(Activity::query()->where('event', 'ota_credential.password-changed')->exists())->toBeFalse();
});

test('a new row is Pending and needs a password', function () {
    ($this->save)([($this->row)(['password' => ''])])
        ->assertSessionHasErrors(['rows.0.password']);

    expect(PropertyOtaCredential::query()->count())->toBe(0);

    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    expect(PropertyOtaCredential::query()->sole()->status)->toBe(OtaCredentialStatus::Pending);
});

test('Other needs a channel name', function () {
    ($this->save)([($this->row)(['ota_channel_id' => (string) $this->other->id, 'channel_name' => ''])])
        ->assertSessionHasErrors(['rows.0.channel_name']);

    ($this->save)([($this->row)(['ota_channel_id' => (string) $this->other->id, 'channel_name' => 'Traveloka'])])
        ->assertSessionHasNoErrors();

    expect(PropertyOtaCredential::query()->sole()->channel_name)->toBe('Traveloka');
});

test('an inactive channel is refused for a new row but stays on a saved row', function () {
    $this->agoda->update(['is_active' => false]);

    ($this->save)([($this->row)()])->assertSessionHasErrors(['rows.0.ota_channel_id']);

    $saved = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id]);

    ($this->save)([($this->row)(['id' => $saved->id, 'password' => ''])])->assertSessionHasNoErrors();

    expect($saved->refresh()->ota_channel_id)->toBe($this->agoda->id);
});

test('rows missing from the submit are removed', function () {
    $kept = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id]);
    PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->booking->id]);

    ($this->save)([($this->row)(['id' => $kept->id, 'password' => ''])])->assertSessionHasNoErrors();

    expect(PropertyOtaCredential::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('saving never creates or removes an onboarding skip', function () {
    ($this->save)([])->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->exists())->toBeFalse();

    OnboardingSkip::query()->create([
        'property_id' => $this->property->id,
        'step' => OnboardingStep::ChannelManager,
        'skipped_by' => $this->owner->id,
        'skipped_at' => now(),
    ]);

    ($this->save)([($this->row)()])->assertSessionHasNoErrors();
    ($this->save)([])->assertSessionHasNoErrors();

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::ChannelManager)->count())->toBe(1);
});

test('the page sends the status and never a password', function () {
    PropertyOtaCredential::factory()->processed()->for($this->property)->create([
        'ota_channel_id' => $this->agoda->id,
        'username' => 'frontdesk@seaview.my',
        'password' => 'agoda-secret',
    ]);

    $this->actingAs($this->owner)
        ->get(route('settings.ota-lists.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ota-lists')
            ->has('savedRows', 1)
            ->where('savedRows.0.hasPassword', true)
            ->where('savedRows.0.username', 'frontdesk@seaview.my')
            ->where('savedRows.0.status.value', 'processed')
            ->missing('savedRows.0.password'))
        ->assertDontSee('agoda-secret');
});

test('a saved row cannot change its channel', function () {
    $saved = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id]);

    ($this->save)([($this->row)(['id' => $saved->id, 'ota_channel_id' => (string) $this->booking->id, 'password' => ''])])
        ->assertSessionHasErrors(['rows.0.ota_channel_id']);

    expect($saved->refresh()->ota_channel_id)->toBe($this->agoda->id);
});

test('a saved Other row cannot change its channel name', function () {
    $saved = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->other->id, 'channel_name' => 'Traveloka']);

    ($this->save)([($this->row)([
        'id' => $saved->id,
        'ota_channel_id' => (string) $this->other->id,
        'channel_name' => 'Trip.com',
        'password' => '',
    ])])->assertSessionHasErrors(['rows.0.channel_name']);

    expect($saved->refresh()->channel_name)->toBe('Traveloka');

    ($this->save)([($this->row)([
        'id' => $saved->id,
        'ota_channel_id' => (string) $this->other->id,
        'channel_name' => 'traveloka',
        'password' => '',
    ])])->assertSessionHasNoErrors();
});

test('a row saved from settings creates its booking source', function () {
    ($this->save)([($this->row)()])->assertSessionHasNoErrors();

    expect(BookingSource::query()->where('property_id', $this->property->id)->sole())
        ->name->toBe('Agoda')
        ->ota_channel_id->toBe($this->agoda->id);
});

test('a password change keeps the same booking source', function () {
    $saved = PropertyOtaCredential::factory()->for($this->property)->create(['ota_channel_id' => $this->agoda->id, 'password' => 'agoda-secret']);
    ($this->save)([($this->row)(['id' => $saved->id, 'password' => ''])])->assertSessionHasNoErrors();
    $source = BookingSource::query()->where('property_id', $this->property->id)->sole();

    ($this->save)([($this->row)(['id' => $saved->id, 'password' => 'new-secret'])])->assertSessionHasNoErrors();

    expect(BookingSource::query()->where('property_id', $this->property->id)->sole()->id)->toBe($source->id);
});
