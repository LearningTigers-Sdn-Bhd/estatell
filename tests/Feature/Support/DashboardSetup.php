<?php

use App\Enums\PermissionEnum;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A live property trading on the business date, and a user who has only the given permissions.
 *
 * @param  list<PermissionEnum>  $permissions
 * @return array{property: Property, user: User}
 */
function dashboardSetup(array $permissions = [PermissionEnum::ReservationsView, PermissionEnum::ReportsView], string $date = '2026-10-12', string $now = '2026-10-12 12:00'): array
{
    test()->travelTo(CarbonImmutable::parse($now));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => $date]);
    $user = User::factory()->for($property)->withPermissions($permissions)->create();

    return ['property' => $property, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dashboardUnit(Property $property, string $arrival, string $departure, string $state = 'confirmed', array $attributes = []): ReservationUnit
{
    $factory = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights();

    $factory = match ($state) {
        'checkedIn' => $factory->checkedIn(),
        'checkedOut' => $factory->checkedOut(),
        'cancelled' => $factory->cancelled(),
        default => $factory,
    };

    return $factory->create(['arrival_date' => $arrival, 'departure_date' => $departure, ...$attributes]);
}

function dashboardLine(Property $property, TransactionCode $code, string $amount, string $date, TransactionKind $kind = TransactionKind::Charge): void
{
    FolioLine::factory()->create([
        'folio_id' => Folio::factory()->create(['property_id' => $property->id])->id,
        'transaction_code_id' => $code->id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
    ]);
}

/**
 * A unit with nights on a reservation the test has already shaped (booking source, company, created_at).
 *
 * @param  array<string, mixed>  $attributes
 */
function dashboardBookedUnit(Property $property, Reservation $reservation, string $arrival, string $departure, string $state = 'confirmed', array $attributes = []): ReservationUnit
{
    $factory = ReservationUnit::factory()->for($reservation)->withNights();

    $factory = match ($state) {
        'checkedIn' => $factory->checkedIn(),
        'checkedOut' => $factory->checkedOut(),
        'cancelled' => $factory->cancelled(),
        default => $factory,
    };

    return $factory->create(['property_id' => $property->id, 'arrival_date' => $arrival, 'departure_date' => $departure, ...$attributes]);
}
