<?php

namespace Database\Seeders;

use App\Actions\BookingEngine\SetUpBookingSite;
use App\Enums\BookingClientType;
use App\Enums\BookingEngineMode;
use App\Features\BookingEngine;
use App\Models\BookingClient;
use App\Models\Property;
use App\Models\RatePlan;
use Illuminate\Database\Seeder;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Opens the booking engine API on the demo: the flag on for both demo properties, Pearlside in Microsite mode (not
 * published) and Boreno in API only mode, their offerable plans bookable online, and the Demo client with the fixed test
 * token from `DEMO_BOOKING_API_TOKEN`. Runs after the property seeders. See docs/29-booking-engine/09-api-only-mode.md#test-mode.
 */
class DemoBookingEngineSeeder extends Seeder
{
    public function run(SetUpBookingSite $setUpBookingSite): void
    {
        $token = self::token();

        foreach (['Pearlside View Resort' => BookingEngineMode::Microsite, 'Boreno Hotel' => BookingEngineMode::ApiOnly] as $name => $mode) {
            $property = Property::query()->where('name', $name)->sole();

            Feature::for($property)->activate(BookingEngine::class);
            $setUpBookingSite($property)->forceFill(['mode' => $mode])->save();
            RatePlan::query()->where('property_id', $property->id)->offerableOnline()->update(['bookable_online' => true]);
        }

        if ($token === null) {
            return;
        }

        $client = BookingClient::query()->create(['type' => BookingClientType::Demo, 'name' => 'Demo test client']);

        $client->tokens()->forceCreate([
            'name' => $client->name,
            'token' => hash('sha256', $token),
            'abilities' => BookingClientType::Demo->abilities(),
        ]);
    }

    /**
     * `DEMO_BOOKING_API_TOKEN`, the token developers send as `Authorization: Bearer {token}`. Without it the demo has no
     * API client. Sanctum reads a `|` as an id separator, so the value must not contain one.
     */
    public static function token(): ?string
    {
        $token = config('estatell.demo.booking_api_token');

        if ($token === null || $token === '') {
            return null;
        }

        if (! is_string($token) || strlen($token) < 32 || str_contains($token, '|')) {
            throw new RuntimeException('Set DEMO_BOOKING_API_TOKEN to at least 32 characters with no "|", or leave it empty.');
        }

        return $token;
    }
}
