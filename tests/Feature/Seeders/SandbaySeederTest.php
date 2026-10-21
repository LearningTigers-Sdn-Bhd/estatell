<?php

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\SandbaySeeder;

test('the Sandbay seeder builds a live resort with rooms, plans, guests and reservations', function () {
    $this->seed(SandbaySeeder::class);

    $property = Property::query()->where('name', 'Sandbay Resort')->sole();

    expect($property->status)->toBe(PropertyStatus::Live)
        ->and($property->businessDates()->count())->toBe(1)
        ->and(Room::query()->where('property_id', $property->id)->count())->toBe(30)
        ->and(RatePlan::query()->where('property_id', $property->id)->orderBy('id')->pluck('name')->all())
        ->toBe(['Room Only', 'Bed & Breakfast', 'Half Board', 'Dive Package', 'Day use'])
        ->and(Guest::query()->where('property_id', $property->id)->count())->toBe(25)
        ->and(User::query()->where('property_id', $property->id)->count())->toBe(4)
        ->and(Reservation::query()->where('property_id', $property->id)->count())->toBe(22)
        ->and(Reservation::query()->where('status', ReservationStatus::Cancelled)->count())->toBe(1)
        ->and(Reservation::query()->whereNotNull('group_name')->count())->toBe(1);
});

test('the Sandbay seeder posts folios, payments and a closed invoice', function () {
    $this->seed(SandbaySeeder::class);

    $property = Property::query()->where('name', 'Sandbay Resort')->sole();
    $folios = Folio::query()->where('property_id', $property->id);

    expect((clone $folios)->where('type', FolioType::ThirdParty)->count())->toBe(2)
        ->and((clone $folios)->where('status', FolioStatus::Closed)->whereNotNull('invoice_number')->count())->toBe(1)
        ->and(Payment::query()->whereIn('folio_id', (clone $folios)->select('id'))->count())->toBeGreaterThanOrEqual(5)
        ->and(FolioLine::query()->whereIn('folio_id', (clone $folios)->select('id'))->whereNotNull('reversal_of_id')->exists())->toBeTrue()
        ->and(FolioLine::query()->whereIn('folio_id', (clone $folios)->select('id'))->whereNotNull('transfer_group_id')->exists())->toBeTrue();
});

test('running the Sandbay seeder twice does not add a second property', function () {
    $this->seed(SandbaySeeder::class);
    $this->seed(SandbaySeeder::class);

    expect(Property::query()->where('name', 'Sandbay Resort')->count())->toBe(1)
        ->and(Reservation::query()->count())->toBe(22);
});

test('the Sandbay seeder adds a Day use plan with hour prices and day use taxes without the flat tax', function () {
    $this->seed(SandbaySeeder::class);

    $property = Property::query()->where('name', 'Sandbay Resort')->sole();
    $plan = RatePlan::query()->where('property_id', $property->id)->where('name', 'Day use')->sole();
    $garden = $property->roomTypes()->where('name', 'Garden Room')->sole();
    $monday = $plan->rates()->where('room_type_id', $garden->id)->whereRaw('extract(isodow from date) = 1')->orderBy('date')->firstOrFail();
    $dayUseTaxes = $property->transactionCodes()->where('system_key', 'day_use_revenue')->sole()->applicableTaxes;

    expect($plan->roomTypes()->count())->toBe(4)
        ->and(substr($plan->day_use_from, 0, 5))->toBe('08:00')
        ->and($plan->day_use_max_hours)->toBe(6)
        ->and($monday->amount)->toBeNull()
        ->and($monday->hourPrices()->orderBy('hours')->pluck('amount', 'hours')->all())
        ->toBe([2 => '60.00', 3 => '80.00', 4 => '100.00', 5 => '120.00', 6 => '140.00'])
        ->and($dayUseTaxes->pluck('code')->all())->not->toContain('TTX')
        ->and($dayUseTaxes->count())->toBeGreaterThan(0)
        ->and($dayUseTaxes->count())->toBe($property->taxes()->where('rate_type', '!=', 'flat')->whereHas('applicableTransactionCodes', fn ($q) => $q->where('system_key', 'room_revenue'))->count());
});

test('the Sandbay seeder books one Confirmed day use in a Garden Room', function () {
    $this->seed(SandbaySeeder::class);

    $unit = ReservationUnit::query()->where('stay_type', 'day_use')->sole();

    expect($unit->status->value)->toBe('confirmed')
        ->and($unit->roomType->name)->toBe('Garden Room')
        ->and($unit->dayUse->hours)->toBe(4)
        ->and(in_array($unit->dayUse->amount, ['100.00', '115.00'], true))->toBeTrue()
        ->and($unit->dayUse->room_id)->not->toBeNull()
        ->and(substr($unit->arrival_time, 0, 5))->toBe('10:00');
});
