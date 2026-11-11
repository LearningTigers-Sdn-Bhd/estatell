<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\BookingClientType;
use App\Enums\BookingEngineMode;
use App\Enums\PropertyStatus;
use App\Enums\UnitStatus;
use App\Features\BookingEngine;
use App\Models\ArInvoice;
use App\Models\BookingClient;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['estatell.demo.password' => 'demo-pass-2026']);
});

test('the demo seeder builds Pearlside View Resort and Boreno Hotel as live properties', function () {
    $this->seed(DemoSeeder::class);

    $pearlside = Property::query()->where('name', 'Pearlside View Resort')->sole();
    $boreno = Property::query()->where('name', 'Boreno Hotel')->sole();

    expect($pearlside->status)->toBe(PropertyStatus::Live)
        ->and($boreno->status)->toBe(PropertyStatus::Live)
        ->and(Room::query()->where('property_id', $pearlside->id)->count())->toBe(30)
        ->and(Room::query()->where('property_id', $boreno->id)->count())->toBe(30)
        ->and(Reservation::query()->where('property_id', $pearlside->id)->count())->toBe(22)
        ->and(Reservation::query()->where('property_id', $boreno->id)->count())->toBe(18)
        ->and(ReservationUnit::query()->where('property_id', $boreno->id)->where('status', UnitStatus::CheckedIn)->count())->toBe(2)
        ->and(Property::query()->where('name', 'Sandbay Resort')->exists())->toBeFalse();
});

test('each demo property has an Owner login that uses the demo password', function (string $email, string $property) {
    $this->seed(DemoSeeder::class);

    $owner = User::query()->where('email', $email)->sole();

    expect($owner->property->name)->toBe($property)
        ->and(Hash::check('demo-pass-2026', $owner->password))->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull();
})->with([
    ['pearlside@demo.estatell.com', 'Pearlside View Resort'],
    ['boreno@demo.estatell.com', 'Boreno Hotel'],
]);

test('the Owner logins on the partner Demo access card match the seeded Owners', function () {
    $this->seed(DemoSeeder::class);

    foreach (config('estatell.demo.logins') as $login) {
        expect(User::query()->where('email', $login['email'])->sole()->property->name)->toBe($login['property']);
    }
});

test('Boreno Hotel has a paid, a part paid and a credited AR invoice', function () {
    $this->seed(DemoSeeder::class);

    $boreno = Property::query()->where('name', 'Boreno Hotel')->sole();
    $invoices = ArInvoice::query()->where('property_id', $boreno->id)->where('type', ArInvoiceType::Invoice)->with('company')->get()->keyBy('company.name');
    $balance = app(ArInvoiceBalance::class);

    expect($invoices)->toHaveCount(3)
        ->and($invoices['Sabah Energy Sdn Bhd']->status)->toBe(ArInvoiceStatus::Settled)
        ->and($balance->forInvoice($invoices['Kinabalu Travel & Tours']))->toBe(bcsub((string) $invoices['Kinabalu Travel & Tours']->amount, '300.00', 2))
        ->and($balance->forInvoice($invoices['Jabatan Pelancongan Sabah']))->toBe(bcsub((string) $invoices['Jabatan Pelancongan Sabah']->amount, '80.00', 2))
        ->and(ArInvoice::query()->where('property_id', $boreno->id)->where('type', ArInvoiceType::CreditNote)->count())->toBe(1);
});

test('the demo seeder refuses to run without a demo password', function () {
    config(['estatell.demo.password' => null]);

    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'DEMO_PASSWORD');

    expect(Property::query()->count())->toBe(0);
});

test('the demo opens the booking engine API on both properties with the fixed test token', function () {
    $token = 'demo_booking_api_token_aaaaaaaaaaaaaaaa';
    config(['estatell.demo.booking_api_token' => $token]);

    $this->seed(DemoSeeder::class);

    $pearlside = Property::query()->where('name', 'Pearlside View Resort')->with('bookingSite')->sole();
    $boreno = Property::query()->where('name', 'Boreno Hotel')->with('bookingSite')->sole();

    expect(Feature::for($pearlside)->active(BookingEngine::class))->toBeTrue()
        ->and(Feature::for($boreno)->active(BookingEngine::class))->toBeTrue()
        ->and($pearlside->bookingSite->mode)->toBe(BookingEngineMode::Microsite)
        ->and($pearlside->bookingSite->published_at)->toBeNull()
        ->and($boreno->bookingSite->mode)->toBe(BookingEngineMode::ApiOnly)
        ->and(BookingClient::query()->sole()->type)->toBe(BookingClientType::Demo);

    foreach ([$pearlside, $boreno] as $property) {
        $this->getJson('/api/booking/v1/properties/'.$property->code, ['Authorization' => 'Bearer '.$token])->assertOk();
        $offers = $this->postJson('/api/booking/v1/properties/'.$property->code.'/offers', ['arrival' => '2026-10-20', 'departure' => '2026-10-22', 'adults' => 2], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->json();

        expect($offers)->not->toBeEmpty();
    }
});

test('the demo has no booking API client without a test token', function () {
    config(['estatell.demo.booking_api_token' => null]);

    $this->seed(DemoSeeder::class);

    expect(BookingClient::query()->count())->toBe(0);
});

test('the demo seeder refuses a short test token, or one with a "|"', function (string $token) {
    config(['estatell.demo.booking_api_token' => $token]);

    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'DEMO_BOOKING_API_TOKEN');

    expect(Property::query()->count())->toBe(0);
})->with([
    'short' => ['too-short'],
    'pipe' => ['1|demo_booking_api_token_aaaaaaaaaaaa'],
]);
