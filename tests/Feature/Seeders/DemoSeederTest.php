<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\PropertyStatus;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceBalance;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

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
