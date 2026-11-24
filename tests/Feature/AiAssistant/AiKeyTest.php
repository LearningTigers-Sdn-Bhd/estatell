<?php

use App\Ai\Agents\OpenAiKeyCheck;
use App\Enums\AiKeyStatus;
use App\Enums\AiScope;
use App\Enums\PermissionEnum;
use App\Features\AiAssistant;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Models\User;
use App\Services\AiAssistant\AiScopes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/AiKeySetup.php';

/**
 * An owner of a property with the AI assistant on and no OpenAI key yet.
 *
 * @return array{owner: User, property: Property}
 */
function aiKeySetup(): array
{
    $owner = User::factory()->owner()->create();
    Feature::for($owner->property)->activate(AiAssistant::class);

    return ['owner' => $owner, 'property' => $owner->property];
}

test('the API key tab and its routes need the feature and settings.manage, and are scoped to the property', function () {
    $owner = User::factory()->owner()->create();
    $code = $owner->property->code;

    $this->actingAs($owner)->get(route('settings.ai-assistant.key', $code))->assertNotFound();
    $this->actingAs($owner)->put(route('settings.ai-assistant.key.update', $code), ['key' => 'sk-test'])->assertNotFound();
    $this->actingAs($owner)->put(route('settings.ai-assistant.monthly-cap.update', $code), ['cap_usd' => '20'])->assertNotFound();

    ['property' => $property] = aiKeySetup();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $this->actingAs($staff)->get(route('settings.ai-assistant.key', $property->code))->assertForbidden();
    $this->actingAs($staff)->delete(route('settings.ai-assistant.key.destroy', $property->code))->assertForbidden();

    $other = Property::factory()->create();
    Feature::for($other)->activate(AiAssistant::class);
    $this->actingAs(aiKeySetup()['owner'])->get(route('settings.ai-assistant.key', $other->code))->assertNotFound();
});

test('saving a key stores it encrypted, tests it and shows only its last four characters', function () {
    ['owner' => $owner, 'property' => $property] = aiKeySetup();
    OpenAiKeyCheck::fake(['OK']);

    $this->actingAs($owner)->put(route('settings.ai-assistant.key.update', $property->code), ['key' => ' sk-proj-secret-a1b2 '])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Your OpenAI key works.']);

    $settings = $property->settings()->first();
    expect($settings->ai_openai_key)->toBe('sk-proj-secret-a1b2')
        ->and($settings->getRawOriginal('ai_openai_key'))->not->toContain('sk-proj')
        ->and($settings->ai_key_status)->toBe(AiKeyStatus::Working)
        ->and($settings->ai_key_checked_at)->not->toBeNull();
    OpenAiKeyCheck::assertPrompted('OK');

    $this->actingAs($owner)->get(route('settings.ai-assistant.key', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-assistant/key')
            ->where('settings.maskedKey', 'sk-…a1b2')
            ->where('settings.status.value', 'working'))
        ->assertDontSee('sk-proj-secret');

    $activity = Activity::query()->where('event', 'ai-assistant.key-saved')->sole();
    expect(json_encode($activity->properties))->not->toContain('sk-proj');
});

test('a key OpenAI refuses, or with no credit, is saved as not working', function (int $status, array $error) {
    ['owner' => $owner, 'property' => $property] = aiKeySetup();
    Http::fake(['api.openai.com/*' => Http::response(['error' => $error], $status)]);

    $this->actingAs($owner)->put(route('settings.ai-assistant.key.update', $property->code), ['key' => 'sk-proj-hotel-9z8y'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'OpenAI refused this key. Check the key and your OpenAI credit.']);

    expect($property->settings()->first()->ai_key_status)->toBe(AiKeyStatus::Failing);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-proj-hotel-9z8y'));
})->with([
    'wrong key' => [401, ['message' => 'Incorrect API key provided.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']],
    'no credit' => [429, ['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']],
]);

test('when OpenAI cannot be reached the key stays untested and the error is reported', function () {
    ['owner' => $owner, 'property' => $property] = aiKeySetup();
    Exceptions::fake();
    OpenAiKeyCheck::fake([fn () => throw ProviderConnectionException::forProvider('openai')]);

    $this->actingAs($owner)->put(route('settings.ai-assistant.key.update', $property->code), ['key' => 'sk-proj-hotel-9z8y'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'We could not reach OpenAI. Try again in a minute.']);

    $settings = $property->settings()->first();
    expect($settings->ai_openai_key)->toBe('sk-proj-hotel-9z8y')
        ->and($settings->ai_key_status)->toBeNull();
    Exceptions::assertReported(ProviderConnectionException::class);
});

test('testing a failing key again brings it back to working', function () {
    ['owner' => $owner, 'property' => $property] = aiKeySetup();
    giveAiKey($property, AiKeyStatus::Failing);
    OpenAiKeyCheck::fake(['OK']);

    $this->actingAs($owner)->post(route('settings.ai-assistant.key.test', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Your OpenAI key works.']);

    expect($property->settings()->first()->ai_key_status)->toBe(AiKeyStatus::Working);
});

test('a key must look like an OpenAI key', function () {
    ['owner' => $owner, 'property' => $property] = aiKeySetup();
    OpenAiKeyCheck::fake();

    $this->actingAs($owner)->put(route('settings.ai-assistant.key.update', $property->code), ['key' => 'AIzaSy-not-openai'])
        ->assertSessionHasErrors(['key' => 'An OpenAI API key starts with sk-.']);

    OpenAiKeyCheck::assertNeverPrompted();
    expect($property->settings()->first()?->ai_openai_key)->toBeNull();
});

test('removing the key turns every place off and is logged', function () {
    ['owner' => $owner, 'property' => $property] = aiKeySetup();
    PropertyAiScope::factory()->for($property)->create(['scope' => AiScope::Staff]);
    giveAiKey($property);
    expect(app(AiScopes::class)->isOn($property, AiScope::Staff))->toBeTrue();

    $this->actingAs($owner)->delete(route('settings.ai-assistant.key.destroy', $property->code))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'OpenAI key removed. The assistant is off.']);

    $settings = $property->settings()->first();
    expect($settings->ai_openai_key)->toBeNull()
        ->and($settings->ai_key_status)->toBeNull()
        ->and(app(AiScopes::class)->isOn($property->refresh(), AiScope::Staff))->toBeFalse()
        ->and(Activity::query()->where('event', 'ai-assistant.key-removed')->count())->toBe(1);

    $this->actingAs($owner)->get(route('settings.ai-assistant.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('settings.hasKey', false));
});

test('the hotel sets its monthly cap in US dollars, and empty is no cap', function () {
    ['owner' => $owner, 'property' => $property] = aiKeySetup();
    $url = route('settings.ai-assistant.monthly-cap.update', $property->code);

    $this->actingAs($owner)->put($url, ['cap_usd' => '20.50'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Monthly cap saved.']);
    expect($property->settings()->first()->ai_monthly_cap_micros)->toBe(20_500_000);

    $this->actingAs($owner)->get(route('settings.ai-assistant.key', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('settings.capUsd', '20.50')->where('settings.status.value', 'none'));

    $this->actingAs($owner)->put($url, ['cap_usd' => ''])->assertRedirect();
    expect($property->settings()->first()->ai_monthly_cap_micros)->toBeNull();

    $this->actingAs($owner)->put($url, ['cap_usd' => '0'])->assertSessionHasErrors('cap_usd');
});
