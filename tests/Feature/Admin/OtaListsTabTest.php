<?php

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

function otaListsRoute(Property $property): string
{
    return route('admin.properties.ota-lists', $property->code);
}

function revealRoute(Property $property, PropertyOtaCredential $credential): string
{
    return route('admin.properties.ota-lists.reveal', [$property->code, $credential->id]);
}

function processedRoute(Property $property, PropertyOtaCredential $credential): string
{
    return route('admin.properties.ota-lists.processed', [$property->code, $credential->id]);
}

test('the OTA lists tab lists the property rows for admins and never sends a password', function () {
    $property = Property::factory()->create();
    $other = OtaChannel::factory()->other()->create(['name' => 'Other']);
    PropertyOtaCredential::factory()->for($property)->create([
        'ota_channel_id' => OtaChannel::factory()->create(['name' => 'Agoda'])->id,
        'property_code' => 'AG-4421',
        'username' => 'agoda-user',
        'password' => 'PlainPass-Tab-Check',
        'market_manager_name' => 'Nadia',
    ]);
    PropertyOtaCredential::factory()->for($property)->other('Traveloka')->processed()->create([
        'ota_channel_id' => $other->id,
        'password' => 'PlainPass-Tab-Other',
    ]);

    $response = $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->get(otaListsRoute($property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/show')
            ->where('tab', 'ota-lists')
            ->has('otaLists', 2)
            ->where('otaLists.0.channel', 'Agoda')
            ->where('otaLists.0.propertyCode', 'AG-4421')
            ->where('otaLists.0.username', 'agoda-user')
            ->where('otaLists.0.marketManagerName', 'Nadia')
            ->where('otaLists.0.canMarkProcessed', true)
            ->where('otaLists.1.channel', 'Traveloka')
            ->where('otaLists.1.status.value', OtaCredentialStatus::Processed->value)
            ->where('otaLists.1.canMarkProcessed', false)
            ->missing('otaLists.0.password')
            ->missing('otaLists.1.password'));

    expect($response->getContent())
        ->not->toContain('PlainPass-Tab-Check')
        ->not->toContain('PlainPass-Tab-Other');
});

test('the OTA lists tab is refused to non-admins, property staff and guests', function () {
    $property = Property::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(otaListsRoute($property))
        ->assertNotFound();
});

test('guests must sign in to see the OTA lists tab', function () {
    $property = Property::factory()->create();

    $this->get(otaListsRoute($property))->assertRedirect(route('login'));
});

test('reveal returns the plaintext password of one credential of the property', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create(['password' => 'PlainPass-Reveal-1']);

    $this->actingAs($admin)
        ->postJson(revealRoute($property, $credential))
        ->assertOk()
        ->assertExactJson(['password' => 'PlainPass-Reveal-1']);
});

test('reveal writes one activity entry that never contains the password', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create(['password' => 'PlainPass-Log-Check']);

    $this->actingAs($admin)->postJson(revealRoute($property, $credential))->assertOk();

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('ota_credential.revealed')
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and($activity->subject->is($property))->toBeTrue()
        ->and($activity->properties->all())->toBe([
            'credential_id' => $credential->id,
            'channel' => $credential->channel->name,
        ])
        ->and(json_encode($activity->toArray()))->not->toContain('PlainPass-Log-Check');
});

test('reveal for a credential of another property is not found and logs nothing', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $otherCredential = PropertyOtaCredential::factory()->create(['password' => 'PlainPass-Other']);

    $this->actingAs($admin)
        ->postJson(revealRoute($property, $otherCredential))
        ->assertNotFound();

    expect(Activity::query()->count())->toBe(0);
});

test('reveal is refused to property staff', function () {
    $staff = User::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($staff->property)->create();

    $this->actingAs($staff)
        ->postJson(revealRoute($staff->property, $credential))
        ->assertNotFound();

    expect(Activity::query()->count())->toBe(0);
});

test('reveal and mark processed need confirmed two-factor when support two-factor is required', function (string $action) {
    config(['estatell.support_require_two_factor' => true]);
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create();
    $admin = User::factory()->admin()->create();

    $response = $action === 'reveal'
        ? $this->actingAs($admin)->postJson(revealRoute($property, $credential))
        : $this->actingAs($admin)->putJson(processedRoute($property, $credential));

    $response->assertForbidden();

    expect($credential->refresh()->status)->toBe(OtaCredentialStatus::Pending)
        ->and(Activity::query()->count())->toBe(0);
})->with(['reveal', 'processed']);

test('mark processed sets the row to processed and logs once', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create();

    $this->actingAs($admin)
        ->put(processedRoute($property, $credential))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Marked processed.']);

    expect($credential->refresh()->status)->toBe(OtaCredentialStatus::Processed);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('ota_credential.processed')
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and($activity->subject->is($property))->toBeTrue();

    $this->put(processedRoute($property, $credential))->assertRedirect();

    expect($credential->refresh()->status)->toBe(OtaCredentialStatus::Processed)
        ->and(Activity::query()->count())->toBe(1);
});

test('mark processed is refused to non-admins and for another property', function () {
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create();

    $this->actingAs(User::factory()->create())
        ->put(processedRoute($property, $credential))
        ->assertNotFound();

    $otherProperty = Property::factory()->create();
    $otherCredential = PropertyOtaCredential::factory()->for($otherProperty)->create();

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->put(processedRoute($property, $otherCredential))
        ->assertNotFound();

    expect($otherCredential->refresh()->status)->toBe(OtaCredentialStatus::Pending)
        ->and(Activity::query()->count())->toBe(0);
});

function otaCredentialFields(array $overrides = []): array
{
    return [
        'ota_channel_id' => '',
        'channel_name' => '',
        'property_code' => 'AG-4421',
        'username' => 'agoda-user',
        'password' => 'PlainPass-Admin-Add',
        'market_manager_name' => '',
        'market_manager_phone' => '',
        'market_manager_email' => '',
        ...$overrides,
    ];
}

test('an admin adds a login as Pending with its booking source and removes an onboarding skip', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->onboarding()->create();
    $channel = OtaChannel::factory()->create(['name' => 'Agoda']);
    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::ChannelManager]);

    $this->actingAs($admin)
        ->post(route('admin.properties.ota-lists.store', $property->code), otaCredentialFields(['ota_channel_id' => (string) $channel->id]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $credential = $property->otaCredentials()->sole();
    $activity = Activity::query()->where('event', 'ota_credential.added')->sole();

    expect($credential->password)->toBe('PlainPass-Admin-Add')
        ->and($credential->status)->toBe(OtaCredentialStatus::Pending)
        ->and(BookingSource::query()->where('property_id', $property->id)->where('name', 'Agoda')->exists())->toBeTrue()
        ->and(OnboardingSkip::query()->where('property_id', $property->id)->exists())->toBeFalse()
        ->and(json_encode($activity->toArray()))->not->toContain('PlainPass-Admin-Add')->not->toContain('agoda-user');
});

test('adding a channel the property already has is refused', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create();

    $this->actingAs($admin)
        ->post(route('admin.properties.ota-lists.store', $property->code), otaCredentialFields(['ota_channel_id' => (string) $credential->ota_channel_id]))
        ->assertSessionHasErrors(['ota_channel_id' => 'This channel is already in the list.']);

    expect($property->otaCredentials()->count())->toBe(1);
});

test('Other needs a channel name when adding', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $other = OtaChannel::factory()->other()->create();

    $this->actingAs($admin)
        ->post(route('admin.properties.ota-lists.store', $property->code), otaCredentialFields(['ota_channel_id' => (string) $other->id]))
        ->assertSessionHasErrors(['channel_name']);
});

test('a blank password keeps the saved one and the row keeps its status', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->processed()->create(['password' => 'PlainPass-Keep']);

    $this->actingAs($admin)
        ->put(route('admin.properties.ota-lists.update', [$property->code, $credential->id]), otaCredentialFields(['username' => 'new-user', 'password' => '']))
        ->assertSessionHasNoErrors();

    expect($credential->refresh()->username)->toBe('new-user')
        ->and($credential->password)->toBe('PlainPass-Keep')
        ->and($credential->status)->toBe(OtaCredentialStatus::Processed)
        ->and(Activity::query()->sole()->event)->toBe('ota_credential.updated');
});

test('a new password sets the row back to Pending and logs without the password', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->processed()->create();

    $this->actingAs($admin)
        ->put(route('admin.properties.ota-lists.update', [$property->code, $credential->id]), otaCredentialFields(['password' => 'PlainPass-New']))
        ->assertSessionHasNoErrors();

    $activity = Activity::query()->sole();

    expect($credential->refresh()->password)->toBe('PlainPass-New')
        ->and($credential->status)->toBe(OtaCredentialStatus::Pending)
        ->and($activity->event)->toBe('ota_credential.password-changed')
        ->and(json_encode($activity->toArray()))->not->toContain('PlainPass-New');
});

test('an admin removes a login and is logged, and its booking source stays', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create();
    $source = BookingSource::factory()->for($property)->create();

    $this->actingAs($admin)
        ->delete(route('admin.properties.ota-lists.destroy', [$property->code, $credential->id]))
        ->assertRedirect();

    expect($credential->fresh())->toBeNull()
        ->and($source->fresh())->not->toBeNull()
        ->and(Activity::query()->sole()->event)->toBe('ota_credential.removed');
});

test('a login of another property cannot be changed or removed', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();
    $foreign = PropertyOtaCredential::factory()->create(['username' => 'someone-else']);

    $this->actingAs($admin)
        ->put(route('admin.properties.ota-lists.update', [$property->code, $foreign->id]), otaCredentialFields())
        ->assertNotFound();
    $this->actingAs($admin)
        ->delete(route('admin.properties.ota-lists.destroy', [$property->code, $foreign->id]))
        ->assertNotFound();

    expect($foreign->refresh()->username)->toBe('someone-else');
});

test('non-admins cannot add, change or remove a login, and admin writes need two-factor', function () {
    $property = Property::factory()->create();
    $credential = PropertyOtaCredential::factory()->for($property)->create(['username' => 'unchanged']);
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)->post(route('admin.properties.ota-lists.store', $property->code), otaCredentialFields())->assertNotFound();
    $this->actingAs($owner)->put(route('admin.properties.ota-lists.update', [$property->code, $credential->id]), otaCredentialFields())->assertNotFound();
    $this->actingAs($owner)->delete(route('admin.properties.ota-lists.destroy', [$property->code, $credential->id]))->assertNotFound();

    config(['estatell.support_require_two_factor' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('admin.properties.ota-lists.destroy', [$property->code, $credential->id]));

    expect($credential->fresh())->not->toBeNull()
        ->and($credential->refresh()->username)->toBe('unchanged');
});

test('the Settings OTA lists page is gone for owners', function () {
    $property = Property::factory()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)->get("/p/{$property->code}/settings/ota-lists")->assertNotFound();
});
