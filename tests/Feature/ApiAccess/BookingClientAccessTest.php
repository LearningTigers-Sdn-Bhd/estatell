<?php

require_once __DIR__.'/../Support/BookingApiSetup.php';

use App\Enums\BookingClientType;
use App\Enums\BookingEngineMode;
use App\Features\BookingEngine;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\Kiosk;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

it('refuses a missing, wrong or revoked token with the shared error', function () {
    bookingApiGet('properties', null)->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
    bookingApiGet('properties', 'est_live_wrong')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');

    BookingClient::factory()->revoked()->withToken(BOOKING_API_TOKEN)->create();
    bookingApiGet('properties')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
});

it('keeps kiosk and booking client tokens out of each other\'s routes', function () {
    Kiosk::factory()->withToken('est_live_kioskwithabookingability', ['*'])->create();
    bookingMicrositeClient(['*']);

    bookingApiGet('properties', 'est_live_kioskwithabookingability')->assertUnauthorized();
    $this->getJson('/api/kiosk/v1/me', ['Authorization' => 'Bearer '.BOOKING_API_TOKEN])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('lets a token reach only the endpoints of its abilities', function () {
    ['property' => $property] = bookingApiProperty();
    bookingMicrositeClient([BookingClient::ABILITY_CONTENT]);

    bookingApiGet('properties/'.$property->code)->assertOk();
    bookingApiGet('properties')->assertUnauthorized();
    bookingApiGet('sites/sand-bay')->assertUnauthorized();
    bookingApiPost('search', bookingApiStay())->assertUnauthorized();
});

it('gives a microsite token no search ability', function () {
    bookingApiProperty();
    bookingMicrositeClient();

    bookingApiGet('properties')->assertOk();
    bookingApiPost('search', bookingApiStay())->assertUnauthorized();
});

it('stamps the client as used at most once a minute', function () {
    $client = bookingMicrositeClient();
    $first = now();

    bookingApiGet('properties')->assertOk();
    $this->travel(30)->seconds();
    bookingApiGet('properties')->assertOk();
    expect($client->refresh()->last_used_at->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    bookingApiGet('properties')->assertOk();
    expect($client->refresh()->last_used_at->equalTo(now()))->toBeTrue();
});

it('answers 404 for a property the microsite client cannot reach', function (Closure $change) {
    ['property' => $property, 'site' => $site] = bookingApiProperty();
    bookingMicrositeClient();
    $change($property, $site);

    bookingApiGet('properties/'.$property->code)->assertNotFound()->assertJsonPath('error.code', 'property_not_found');
    bookingApiGet('sites/sand-bay')->assertNotFound();
    expect(bookingApiGet('properties')->json())->toBe([]);
})->with([
    'flag off' => [fn (Property $property) => Feature::for($property)->deactivate(BookingEngine::class)],
    'unpublished' => [fn (Property $property, PropertyBookingSite $site) => $site->update(['published_at' => null])],
    'API only mode' => [fn (Property $property, PropertyBookingSite $site) => $site->update(['mode' => 'api-only'])],
    'suspended' => [fn (Property $property) => $property->forceFill(['suspended_at' => now()])->save()],
]);

it('answers 404 for an unknown code and slug', function () {
    bookingMicrositeClient();

    bookingApiGet('properties/ES9999')->assertNotFound();
    bookingApiGet('sites/nowhere')->assertNotFound();
});

it('finds a property by its code in lower case', function () {
    ['property' => $property] = bookingApiProperty();
    bookingMicrositeClient();

    bookingApiGet('properties/'.mb_strtolower($property->code))->assertOk()->assertJsonPath('code', $property->code);
});

it('lets an API only client reach only its own property, in API only mode', function () {
    ['property' => $own, 'site' => $site] = bookingApiProperty();
    $site->update(['mode' => 'api-only', 'published_at' => null]);
    BookingClient::factory()->apiOnly($own)->withToken(BOOKING_API_TOKEN)->create();
    $other = Property::factory()->create();
    Feature::for($other)->activate(BookingEngine::class);
    PropertyBookingSite::factory()->for($other)->apiOnly()->published()->create();

    bookingApiGet('properties/'.$own->code)->assertOk();
    bookingApiGet('properties/'.$other->code)->assertNotFound();
});

it('lets a demo client reach a property with a booking site in either mode', function () {
    ['property' => $microsite] = bookingApiProperty();
    ['property' => $apiOnly, 'site' => $site] = bookingApiProperty('palm-beach');
    $site->update(['mode' => BookingEngineMode::ApiOnly, 'published_at' => null]);
    ['property' => $noSite, 'site' => $removed] = bookingApiProperty('coral-bay');
    $removed->delete();
    BookingClient::factory()->demo()->withToken(BOOKING_API_TOKEN)->create();

    bookingApiGet('properties/'.$microsite->code)->assertOk();
    bookingApiGet('properties/'.$apiOnly->code)->assertOk();
    bookingApiGet('properties/'.$noSite->code)->assertNotFound();

    Feature::for($apiOnly)->deactivate(BookingEngine::class);
    bookingApiGet('properties/'.$apiOnly->code)->assertNotFound();
});

it('counts the rate limit per token and property', function () {
    ['property' => $first] = bookingApiProperty();
    ['property' => $second] = bookingApiProperty('palm-beach');
    bookingMicrositeClient();
    RateLimiter::clear('api-token');

    foreach (range(1, 120) as $request) {
        bookingApiGet('properties/'.$first->code)->assertOk();
    }

    bookingApiGet('properties/'.$first->code)->assertTooManyRequests()->assertJsonPath('error.code', 'rate_limited');
    bookingApiGet('properties/'.$second->code)->assertOk();
    bookingApiGet('properties')->assertOk();
});

it('refuses an API only client with no property, and a microsite client with one', function () {
    $property = Property::factory()->create();

    expect(fn () => DB::table('booking_clients')->insert(['type' => 'api-only', 'name' => 'x']))->toThrow(Exception::class);
    expect(fn () => DB::table('booking_clients')->insert(['type' => 'microsite', 'name' => 'x', 'property_id' => $property->id]))->toThrow(Exception::class);
});

it('creates the microsite client with its token once', function () {
    expect(Artisan::call('booking-engine:create-microsite-client'))->toBe(0);
    $client = BookingClient::query()->sole();
    expect($client->tokens()->sole()->abilities)->toBe([BookingClient::ABILITY_LISTING, BookingClient::ABILITY_SITE, BookingClient::ABILITY_CONTENT, BookingClient::ABILITY_CHECKOUT]);

    expect(Artisan::call('booking-engine:create-microsite-client'))->toBe(1);
    expect(BookingClient::query()->count())->toBe(1);
    expect(PersonalAccessToken::query()->count())->toBe(1);

    expect(Artisan::output())->toContain('already exists');
});

it('replaces the microsite token, keeps the client and its holds, and stops the old token at once', function () {
    ['property' => $property] = bookingApiProperty();
    $client = bookingMicrositeClient();
    $hold = BookingHold::factory()->for($property)->create(['booking_client_id' => $client->id]);

    expect(Artisan::call('booking-engine:create-microsite-client', ['--replace' => true]))->toBe(0);
    $newToken = trim(strtok(Artisan::output(), "\n"));

    bookingApiGet('properties/'.$property->code)->assertUnauthorized();
    bookingApiGet('properties/'.$property->code, $newToken)->assertOk();
    bookingApiGet('holds/'.$hold->token, $newToken)->assertOk();

    expect(BookingClient::query()->sole()->id)->toBe($client->id)
        ->and($client->tokens()->sole()->abilities)->toBe(BookingClientType::Microsite->abilities())
        ->and(Activity::query()->where('event', 'booking-api.microsite-token-replaced')->count())->toBe(1);
});

it('refuses to replace the microsite token when there is no active client', function () {
    BookingClient::factory()->revoked()->withToken(BOOKING_API_TOKEN)->create();

    expect(Artisan::call('booking-engine:create-microsite-client', ['--replace' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('no active microsite client')
        ->and(PersonalAccessToken::query()->count())->toBe(1);
});
