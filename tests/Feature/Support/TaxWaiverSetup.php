<?php

use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A live property trading on 2026-10-12 with an 8% Service Tax and a flat 10.00 Tourism tax on room revenue, and a
 * checked-in unit for 2 nights from that date at 100.00 a night.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, sst: Tax, tourism: Tax}
 */
function wtSetup(DefaultRoleEnum $role = DefaultRoleEnum::FrontDesk): array
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $sst = Tax::factory()->for($property)->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'name' => 'Service Tax', 'code' => 'SST']);
    $tourism = Tax::factory()->for($property)->create(['rate' => '10.00', 'rate_type' => TaxRateType::Flat, 'name' => 'Tourism tax', 'code' => 'TTX', 'collect_at_check_in' => true, 'can_be_waived' => true]);
    $sst->applicableTransactionCodes()->attach($room);
    $tourism->applicableTransactionCodes()->attach($room);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        'adults' => 1,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '100.00', 'amount' => '100.00']);

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'unit' => $unit, 'sst' => $sst, 'tourism' => $tourism];
}

function wtPostNight(array $setup, string $date): void
{
    BusinessDate::query()->where('property_id', $setup['property']->id)->update(['date' => $date]);
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse($date));
}

/**
 * The live (not reversed) tax amounts of the unit's posted nights, by tax line description.
 *
 * @return array<string, string>
 */
function wtLiveTaxes(): array
{
    return FolioLine::query()
        ->where('kind', TransactionKind::Tax)
        ->whereNull('reversal_of_id')
        ->whereDoesntHave('reversal')
        ->orderBy('id')
        ->get()
        ->groupBy('description')
        ->map(fn ($lines): string => number_format($lines->sum(fn (FolioLine $line): float => (float) $line->amount), 2, '.', ''))
        ->all();
}
