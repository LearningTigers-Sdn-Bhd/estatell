<?php

require_once __DIR__.'/../Support/CurlecSetup.php';

use App\Actions\BookingEngine\AddBookingReturnAddress;
use App\Enums\BookingClientType;
use App\Enums\BookingEngineMode;
use App\Enums\PermissionEnum;
use App\Models\BookingClient;
use App\Models\BookingReturnAddress;
use App\Models\GatewayPayment;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

const API_ONLY_TOKEN = 'est_live_apionlytokenbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * A reachable property switched to API only mode, and its owner.
 *
 * @return array<string, mixed> The `bookingApiProperty()` keys plus `owner`.
 */
function apiOnlySetup(): array
{
    $setup = bookingApiProperty();
    $setup['site']->update(['mode' => BookingEngineMode::ApiOnly]);

    return [...$setup, 'owner' => User::factory()->owner()->create(['property_id' => $setup['property']->id])];
}

/**
 * The token the owner just created, read from the flash data of the redirect.
 */
function issuedApiToken(TestResponse $response): string
{
    return (string) $response->assertRedirect()->getSession()->get('inertia.flash_data.apiToken');
}

// The API tab

test('the API tab shows the API address, the token state and the return addresses', function () {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();
    BookingReturnAddress::factory()->for($property)->create(['url' => 'https://www.sandbay.com/done']);

    $this->actingAs($owner)->get(route('settings.booking-website.api', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/booking-website/api')
            ->where('api.apiAddress', route('api.booking.v1.properties.show', ['code' => $property->code]))
            ->where('api.hasToken', false)
            ->where('api.returnAddresses.0.url', 'https://www.sandbay.com/done')
            ->where('api.returnAddressLimit', AddBookingReturnAddress::LIMIT)
            ->where('api.docsUrl', config('estatell.booking_api_docs_url')));
});

test('the API tab needs the API permission, its own property and API only mode', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = apiOnlySetup();
    $manager = User::factory()->for($property)->withPermissions([PermissionEnum::BookingWebsiteManage])->create();

    $this->actingAs($manager)->get(route('settings.booking-website.api', $property))->assertForbidden();
    $this->actingAs($manager)->post(route('settings.booking-website.api.token.store', $property))->assertForbidden();
    $this->actingAs($owner)->get(route('settings.booking-website.api', Property::factory()->create()))->assertNotFound();

    $site->update(['mode' => BookingEngineMode::Microsite]);

    $this->actingAs($owner)->get(route('settings.booking-website.api', $property))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.booking-website.api.token.store', $property))->assertNotFound();
    expect(BookingClient::query()->count())->toBe(0);
});

test('support can open the API tab during view as, but not change it', function () {
    $impersonation = app(Impersonation::class);

    expect($impersonation->isBlockedRoute('settings.booking-website.api', 'GET'))->toBeFalse()
        ->and($impersonation->isBlockedRoute('settings.booking-website.api.token.store', 'POST'))->toBeTrue()
        ->and($impersonation->isBlockedRoute('settings.booking-website.api.token.destroy', 'DELETE'))->toBeTrue()
        ->and($impersonation->isBlockedRoute('settings.booking-website.api.return-addresses.store', 'POST'))->toBeTrue()
        ->and($impersonation->isBlockedRoute('settings.booking-website.api.return-addresses.destroy', 'DELETE'))->toBeTrue();
});

// The token

test('creating a token shows it once, and it reaches the property', function () {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();

    $token = issuedApiToken($this->actingAs($owner)->post(route('settings.booking-website.api.token.store', $property)));

    $client = BookingClient::query()->sole();
    expect($client->type)->toBe(BookingClientType::ApiOnly)
        ->and($client->property_id)->toBe($property->id)
        ->and($client->tokens()->sole()->abilities)->toBe(BookingClientType::ApiOnly->abilities())
        ->and(Activity::query()->where('event', 'booking-website.api-token-created')->sole()->causer_id)->toBe($owner->id);

    bookingApiGet('properties/'.$property->code, $token)->assertOk();

    $this->actingAs($owner)->get(route('settings.booking-website.api', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('api.hasToken', true)
            ->where('api.tokenCreatedAt', now()->toIso8601String())
            ->where('api.tokenLastUsedAt', now()->toIso8601String()));
});

test('replacing the token stops the old one and keeps the same client', function () {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();
    $client = BookingClient::factory()->apiOnly($property)->withToken(API_ONLY_TOKEN)->create(['last_used_at' => now()]);

    $token = issuedApiToken($this->actingAs($owner)->post(route('settings.booking-website.api.token.store', $property)));

    bookingApiGet('properties/'.$property->code, API_ONLY_TOKEN)->assertUnauthorized();
    bookingApiGet('properties/'.$property->code, $token)->assertOk();
    expect(BookingClient::query()->sole()->id)->toBe($client->id)
        ->and($client->tokens()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'booking-website.api-token-replaced')->exists())->toBeTrue();
});

test('revoking the token stops it at once, and a second revoke changes nothing', function () {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();
    $client = BookingClient::factory()->apiOnly($property)->withToken(API_ONLY_TOKEN)->create();

    $this->actingAs($owner)->delete(route('settings.booking-website.api.token.destroy', $property))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'API token revoked.']);

    bookingApiGet('properties/'.$property->code, API_ONLY_TOKEN)->assertUnauthorized();
    expect($client->refresh()->revoked_at)->not->toBeNull()->and($client->tokens()->count())->toBe(0);

    $this->actingAs($owner)->delete(route('settings.booking-website.api.token.destroy', $property))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'There is no token to revoke.']);
    expect(Activity::query()->where('event', 'booking-website.api-token-revoked')->count())->toBe(1);

    $token = issuedApiToken($this->actingAs($owner)->post(route('settings.booking-website.api.token.store', $property)));
    bookingApiGet('properties/'.$property->code, $token)->assertOk();
    expect($client->refresh()->revoked_at)->toBeNull();
});

// Return addresses

test('an owner adds and removes return addresses', function () {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();

    $this->actingAs($owner)->post(route('settings.booking-website.api.return-addresses.store', $property), ['url' => ' https://www.sandbay.com/booking/done?step=3 '])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Return address added.']);

    $address = BookingReturnAddress::query()->sole();
    expect($address->url)->toBe('https://www.sandbay.com/booking/done?step=3');

    $this->actingAs($owner)->post(route('settings.booking-website.api.return-addresses.store', $property), ['url' => 'https://www.sandbay.com/booking/done?step=3'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'That address is already added, or the list is full.']);

    $this->actingAs($owner)->delete(route('settings.booking-website.api.return-addresses.destroy', [$property, $address]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Return address removed.']);

    expect(BookingReturnAddress::query()->count())->toBe(0)
        ->and(Activity::query()->whereIn('event', ['booking-website.return-address-added', 'booking-website.return-address-removed'])->count())->toBe(2);
});

test('a return address must be a full https address with no # part', function (string $url) {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();

    $this->actingAs($owner)->post(route('settings.booking-website.api.return-addresses.store', $property), ['url' => $url])
        ->assertSessionHasErrors('url');

    expect(BookingReturnAddress::query()->count())->toBe(0);
})->with(['http://www.sandbay.com/done', 'www.sandbay.com/done', 'https://www.sandbay.com/done#paid', '']);

test('a property has at most ten return addresses', function () {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();
    BookingReturnAddress::factory()->for($property)->count(AddBookingReturnAddress::LIMIT)->create();

    $this->actingAs($owner)->post(route('settings.booking-website.api.return-addresses.store', $property), ['url' => 'https://www.sandbay.com/eleven'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'That address is already added, or the list is full.']);

    expect(BookingReturnAddress::query()->count())->toBe(AddBookingReturnAddress::LIMIT);
});

test('an owner cannot remove another property\'s return address', function () {
    ['owner' => $owner, 'property' => $property] = apiOnlySetup();
    $other = BookingReturnAddress::factory()->create();

    $this->actingAs($owner)->delete(route('settings.booking-website.api.return-addresses.destroy', [$property, $other]))->assertNotFound();

    expect($other->fresh())->not->toBeNull();
});

// Paying from the owner's website

/**
 * The Curlec setup in API only mode: the hold belongs to an API only client with the known token, and the property has
 * one approved return address.
 *
 * @return array<string, mixed>
 */
function apiOnlyPaymentSetup(): array
{
    $setup = curlecSetup();
    $setup['site']->update(['mode' => BookingEngineMode::ApiOnly]);
    $client = BookingClient::factory()->apiOnly($setup['property'])->withToken(API_ONLY_TOKEN)->create();
    BookingReturnAddress::factory()->for($setup['property'])->create(['url' => 'https://www.sandbay.com/done?lang=en']);
    $setup['client'] = $client;

    return [...$setup, 'hold' => curlecHold($setup)];
}

function postApiOnlyPayment(string $token, array $body): TestResponse
{
    return test()->postJson("/api/booking/v1/holds/{$token}/payment", $body, ['Authorization' => 'Bearer '.API_ONLY_TOKEN]);
}

test('an API only payment must name an approved return address', function (array $body) {
    $setup = apiOnlyPaymentSetup();

    postApiOnlyPayment($setup['hold']->token, $body)->assertStatus(422)->assertJsonPath('error.code', 'return_url_not_allowed');

    expect($setup['gateway']->orders)->toBe([]);
})->with([
    'missing' => [[]],
    'not approved' => [['return_url' => 'https://evil.example.com/done']],
    'not a string' => [['return_url' => ['https://www.sandbay.com/done?lang=en']]],
]);

test('an API only payment keeps the address it named, and the guest returns there', function () {
    $setup = apiOnlyPaymentSetup();
    $hold = $setup['hold'];

    postApiOnlyPayment($hold->token, ['return_url' => 'https://www.sandbay.com/done?lang=en'])->assertOk()->assertJsonPath('order_id', 'order_1');

    expect(GatewayPayment::query()->sole()->return_url)->toBe('https://www.sandbay.com/done?lang=en');

    $signature = hash_hmac('sha256', 'order_1|pay_123', CURLEC_KEY_SECRET);
    $this->post("/webhooks/curlec/return/{$hold->token}", ['razorpay_order_id' => 'order_1', 'razorpay_payment_id' => 'pay_123', 'razorpay_signature' => $signature])
        ->assertStatus(303)
        ->assertRedirect("https://www.sandbay.com/done?lang=en&hold={$hold->token}&payment=confirming");

    BookingReturnAddress::query()->delete();
    $this->post("/webhooks/curlec/return/{$hold->token}", [])
        ->assertRedirect("https://www.sandbay.com/done?lang=en&hold={$hold->token}&payment=failed");
});

test('a retry may name another approved address, on the same order', function () {
    $setup = apiOnlyPaymentSetup();
    BookingReturnAddress::factory()->for($setup['property'])->create(['url' => 'https://www.sandbay.com/selesai']);

    postApiOnlyPayment($setup['hold']->token, ['return_url' => 'https://www.sandbay.com/done?lang=en'])->assertOk();
    postApiOnlyPayment($setup['hold']->token, ['return_url' => 'https://www.sandbay.com/selesai'])->assertOk();

    expect($setup['gateway']->orders)->toHaveCount(1)
        ->and(GatewayPayment::query()->sole()->return_url)->toBe('https://www.sandbay.com/selesai');
});

test('the microsite ignores a return address and keeps its own page', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);

    test()->postJson("/api/booking/v1/holds/{$hold->token}/payment", [], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN])->assertOk();
    test()->postJson("/api/booking/v1/holds/{$hold->token}/payment", ['return_url' => 'https://evil.example.com'], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN])->assertOk();

    expect(GatewayPayment::query()->sole()->return_url)->toBeNull();
});

test('the demo client may name any http or https return address, or none', function (?string $url, ?string $saved) {
    $setup = curlecSetup();
    $setup['client'] = BookingClient::factory()->demo()->withToken(API_ONLY_TOKEN)->create();
    $hold = curlecHold($setup);

    postApiOnlyPayment($hold->token, $url === null ? [] : ['return_url' => $url])->assertOk();

    expect(GatewayPayment::query()->sole()->return_url)->toBe($saved);
})->with([
    'localhost' => ['http://localhost:4321/done', 'http://localhost:4321/done'],
    'https' => ['https://dev.sandbay.com/done?lang=en', 'https://dev.sandbay.com/done?lang=en'],
    'none' => [null, null],
]);

test('the demo client cannot name a return address that is not a plain web address', function (string $url) {
    $setup = curlecSetup();
    $setup['client'] = BookingClient::factory()->demo()->withToken(API_ONLY_TOKEN)->create();

    postApiOnlyPayment(curlecHold($setup)->token, ['return_url' => $url])->assertStatus(422)->assertJsonPath('error.code', 'return_url_not_allowed');

    expect($setup['gateway']->orders)->toBe([]);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'ftp' => ['ftp://dev.sandbay.com/done'],
    'fragment' => ['http://localhost:4321/done#top'],
    'too long' => ['https://dev.sandbay.com/'.str_repeat('a', 480)],
]);

test('the tabs get the property\'s mode, so each mode shows only its own tabs', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = apiOnlySetup();

    $this->actingAs($owner)->get(route('settings.booking-website.general', $property))
        ->assertInertia(fn (Assert $page) => $page->where('bookingMode', 'api-only'));

    $site->update(['mode' => BookingEngineMode::Microsite]);

    $this->actingAs($owner)->get(route('settings.booking-website.general', $property))
        ->assertInertia(fn (Assert $page) => $page->where('bookingMode', 'microsite'));
});

test('every tab gets the header badges for the publish status and the mode', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = apiOnlySetup();

    $this->actingAs($owner)->get(route('settings.booking-website.rates', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bookingWebsiteHeader.status.value', 'published')
            ->where('bookingWebsiteHeader.status.tone', 'success')
            ->where('bookingWebsiteHeader.mode.value', 'api-only')
            ->where('bookingWebsiteHeader.mode.label', 'API only')
            ->where('bookingWebsiteHeader.mode.tone', 'info'));

    $site->update(['mode' => BookingEngineMode::Microsite, 'published_at' => null]);

    $this->actingAs($owner)->get(route('settings.booking-website.rates', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bookingWebsiteHeader.status.value', 'not-published')
            ->where('bookingWebsiteHeader.status.tone', 'neutral')
            ->where('bookingWebsiteHeader.mode.value', 'microsite'));
});
