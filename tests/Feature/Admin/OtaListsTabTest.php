<?php

use App\Enums\OtaCredentialStatus;
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
