<?php

use App\Enums\AiScope;
use App\Enums\PermissionEnum;
use App\Features\AiAssistant;
use App\Features\Concierge;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Models\User;
use App\Services\AiAssistant\AiScopes;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/AiKeySetup.php';

/**
 * An owner of a property with the AI assistant feature on.
 *
 * @return array{owner: User, property: Property}
 */
function aiAssistantSetup(): array
{
    $owner = User::factory()->owner()->create();
    Feature::for($owner->property)->activate(AiAssistant::class);
    releaseExperimentalAiPlaces();

    return ['owner' => $owner, 'property' => $owner->property];
}

function aiScopeRow(Property $property, AiScope $scope, bool $allowed = true, bool $enabled = true): PropertyAiScope
{
    return PropertyAiScope::factory()->for($property)->create(['scope' => $scope, 'allowed' => $allowed, 'enabled' => $enabled]);
}

test('the settings page and its routes answer 404 while the AI assistant feature is off', function () {
    $owner = User::factory()->owner()->create();
    $code = $owner->property->code;

    $this->actingAs($owner)->get(route('settings.ai-assistant.places', $code))->assertNotFound();
    $this->actingAs($owner)->get(route('settings.ai-assistant.capabilities', $code))->assertNotFound();
    $this->actingAs($owner)->get(route('settings.ai-assistant.index', $code))->assertNotFound();
    $this->actingAs($owner)->put(route('settings.ai-assistant.scopes.update', $code), ['scopes' => ['staff' => true]])->assertNotFound();
    $this->actingAs($owner)->put(route('settings.ai-assistant.handover-message.update', $code), ['handover_message' => 'Hi'])->assertNotFound();
});

test('the settings page needs settings.manage and is scoped to the property', function () {
    ['property' => $property] = aiAssistantSetup();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($staff)->get(route('settings.ai-assistant.places', $property->code))->assertForbidden();

    $other = Property::factory()->create();
    Feature::for($other)->activate(AiAssistant::class);
    $this->actingAs(aiAssistantSetup()['owner'])->get(route('settings.ai-assistant.places', $other->code))->assertNotFound();
});

test('each tab shows only its own allowed scopes whose feature is on', function () {
    ['owner' => $owner, 'property' => $property] = aiAssistantSetup();
    aiScopeRow($property, AiScope::Staff, enabled: false);
    aiScopeRow($property, AiScope::HowTo);
    aiScopeRow($property, AiScope::Concierge);
    aiScopeRow($property, AiScope::RoomQuotes, allowed: false);

    $this->actingAs($owner)->get(route('settings.ai-assistant.places', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-assistant/places')
            ->has('settings.scopes', 1)
            ->where('settings.scopes.0.scope', 'staff')
            ->where('settings.scopes.0.enabled', false));

    $this->actingAs($owner)->get(route('settings.ai-assistant.capabilities', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-assistant/capabilities')
            ->has('settings.scopes', 1)
            ->where('settings.scopes.0.scope', 'how-to')
            ->where('settings.scopes.0.note', 'Works only while Staff (Ask Estatell) is on.'));

    $this->actingAs($owner)->get(route('settings.ai-assistant.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-assistant/general')
            ->missing('settings.scopes')
            ->where('settings.handoverMessage', null)
            ->where('settings.defaultHandoverMessage', "Good question! I've passed this to our front desk so they can help you personally. You can also contact them directly."));
});

test('the hotel turns its allowed scopes on and off, and the change is logged', function () {
    ['owner' => $owner, 'property' => $property] = aiAssistantSetup();
    $staff = aiScopeRow($property, AiScope::Staff, enabled: false);
    $howTo = aiScopeRow($property, AiScope::HowTo);

    $this->actingAs($owner)
        ->put(route('settings.ai-assistant.scopes.update', $property->code), ['scopes' => ['staff' => true, 'how-to' => false]])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'AI assistant switches saved.']);

    expect($staff->refresh()->enabled)->toBeTrue()
        ->and($howTo->refresh()->enabled)->toBeFalse()
        ->and(Activity::query()->where('event', 'ai-assistant.scopes-updated')->sole()->properties['changed'])->toBe(['staff', 'how-to']);
});

test('the hotel cannot turn on a scope the admin has not allowed', function () {
    ['owner' => $owner, 'property' => $property] = aiAssistantSetup();
    $staff = aiScopeRow($property, AiScope::Staff, enabled: false);
    $quotes = aiScopeRow($property, AiScope::RoomQuotes, allowed: false, enabled: false);

    $this->actingAs($owner)
        ->put(route('settings.ai-assistant.scopes.update', $property->code), ['scopes' => ['staff' => true, 'room-quotes' => true]])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Estatell no longer allows one of these. Reload the page.']);

    expect($staff->refresh()->enabled)->toBeFalse()
        ->and($quotes->refresh()->enabled)->toBeFalse()
        ->and(Activity::query()->where('event', 'ai-assistant.scopes-updated')->exists())->toBeFalse();
});

test('an unknown scope is a validation error', function () {
    ['owner' => $owner, 'property' => $property] = aiAssistantSetup();

    $this->actingAs($owner)
        ->put(route('settings.ai-assistant.scopes.update', $property->code), ['scopes' => ['payments' => true]])
        ->assertSessionHasErrors('scopes');
});

test('the hotel saves the handover message, and blank clears it', function () {
    ['owner' => $owner, 'property' => $property] = aiAssistantSetup();
    $url = route('settings.ai-assistant.handover-message.update', $property->code);

    $this->actingAs($owner)->put($url, ['handover_message' => '  Our team will WhatsApp you soon.  '])->assertRedirect();
    expect($property->settings()->first()->ai_handover_message)->toBe('Our team will WhatsApp you soon.');

    $this->actingAs($owner)->put($url, ['handover_message' => ''])->assertRedirect();
    expect($property->settings()->first()->ai_handover_message)->toBeNull()
        ->and(Activity::query()->where('event', 'ai-assistant.handover-message-saved')->count())->toBe(2);
});

test('a scope is on only with the feature, its required feature and place, both layers, and a key', function () {
    ['property' => $property] = aiAssistantSetup();
    $scopes = app(AiScopes::class);
    $concierge = aiScopeRow($property, AiScope::Concierge);
    aiScopeRow($property, AiScope::StayDetails);
    giveAiKey($property);

    expect($scopes->isOn($property, AiScope::Concierge))->toBeFalse()
        ->and($scopes->isOn($property, AiScope::StayDetails))->toBeFalse();

    Feature::for($property)->activate(Concierge::class);
    expect($scopes->isOn($property, AiScope::Concierge))->toBeTrue()
        ->and($scopes->isOn($property, AiScope::StayDetails))->toBeTrue();

    $concierge->update(['enabled' => false]);
    expect($scopes->isOn($property, AiScope::StayDetails))->toBeFalse();

    $concierge->update(['enabled' => true, 'allowed' => false]);
    expect($scopes->isOn($property, AiScope::Concierge))->toBeFalse();

    $concierge->update(['allowed' => true]);
    Feature::for($property)->deactivate(AiAssistant::class);
    expect($scopes->isOn($property, AiScope::Concierge))->toBeFalse()
        ->and($scopes->isOn($property, AiScope::RoomQuotes))->toBeFalse();

    Feature::for($property)->activate(AiAssistant::class);
    $property->settings->forceFill(['ai_openai_key' => null])->save();
    expect($scopes->isOn($property->refresh(), AiScope::Concierge))->toBeFalse();
});

test('admins allow and stop a scope, and stopping keeps the hotel switch', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    ['property' => $property] = aiAssistantSetup();
    $url = route('admin.properties.ai-scopes.update', ['property' => $property->code, 'scope' => 'staff']);

    $this->actingAs($admin)->put($url, ['allowed' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Staff (Ask Estatell) allowed.']);
    $row = $property->aiScopes()->sole();
    expect($row->allowed)->toBeTrue()->and($row->enabled)->toBeFalse();

    $row->update(['enabled' => true]);
    $this->actingAs($admin)->put($url, ['allowed' => false])->assertRedirect();

    expect($row->refresh()->allowed)->toBeFalse()
        ->and($row->enabled)->toBeTrue()
        ->and(Activity::query()->whereIn('event', ['ai-assistant.scope-allowed', 'ai-assistant.scope-stopped'])->count())->toBe(2);
});

test('admins cannot allow a scope while the AI assistant feature is off', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.properties.ai-scopes.update', ['property' => $property->code, 'scope' => 'staff']), ['allowed' => true])
        ->assertRedirect();

    expect($property->aiScopes()->exists())->toBeFalse();
});

test('admins see whether the hotel has a working key, never the key', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    ['property' => $property] = aiAssistantSetup();
    $features = route('admin.properties.features', $property->code);

    $this->actingAs($admin)->get($features)
        ->assertInertia(fn (Assert $page) => $page
            ->where('aiAssistant.keyStatus.value', 'none')
            ->has('aiAssistant.scopes', count(AiScope::cases())));

    giveAiKey($property);

    $this->actingAs($admin)->get($features)
        ->assertInertia(fn (Assert $page) => $page->where('aiAssistant.keyStatus.value', 'working'))
        ->assertDontSee('sk-test-hotel-1234');
});

test('an experimental place stays hidden and off until its platform switch is on', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    ['owner' => $owner, 'property' => $property] = aiAssistantSetup();
    giveAiKey($property);
    aiScopeRow($property, AiScope::Staff);
    aiScopeRow($property, AiScope::HowTo);
    aiScopeRow($property, AiScope::BookingEngine);
    releaseExperimentalAiPlaces(bookingWebsite: false, staffHelper: false);
    $scopes = app(AiScopes::class);

    expect($scopes->isOn($property, AiScope::Staff))->toBeFalse()
        ->and($scopes->isOn($property, AiScope::HowTo))->toBeFalse()
        ->and($scopes->forHotel($property))->toBe([]);

    $this->actingAs($admin)->get(route('admin.properties.features', $property->code))
        ->assertInertia(fn (Assert $page) => $page->has('aiAssistant.scopes', 5));

    $this->actingAs($admin)
        ->put(route('admin.properties.ai-scopes.update', ['property' => $property->code, 'scope' => 'staff']), ['allowed' => false])
        ->assertRedirect();
    expect($property->aiScopes()->where('scope', AiScope::Staff)->sole()->allowed)->toBeTrue()
        ->and(Activity::query()->where('event', 'ai-assistant.scope-stopped')->exists())->toBeFalse();

    $this->actingAs($owner)->put(route('settings.ai-assistant.scopes.update', $property->code), ['scopes' => ['staff' => false]])
        ->assertInertiaFlash('toast.type', 'error');

    releaseExperimentalAiPlaces(bookingWebsite: false, staffHelper: true);

    expect($scopes->isOn($property, AiScope::Staff))->toBeTrue()
        ->and($scopes->isOn($property, AiScope::HowTo))->toBeTrue()
        ->and($scopes->isReleased(AiScope::BookingEngine))->toBeFalse();
});

test('only platform admins set the scope', function () {
    ['owner' => $owner, 'property' => $property] = aiAssistantSetup();

    $this->actingAs($owner)
        ->put(route('admin.properties.ai-scopes.update', ['property' => $property->code, 'scope' => 'staff']), ['allowed' => true])
        ->assertNotFound();

    expect($property->aiScopes()->exists())->toBeFalse();
});
