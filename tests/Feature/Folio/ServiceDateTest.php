<?php

use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\BusinessDate;
use App\Models\Folio;
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
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A live property trading on 2026-10-13 with a checked-in unit of 2 nights from 2026-10-12, each priced at 300.00
 * with 8% tax, so the night of 2026-10-12 posts late and the night of 2026-10-13 posts on time.
 *
 * @return array{user: User, property: Property, unit: ReservationUnit}
 */
function sdSetup(): array
{
    test()->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-13']);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false, 'name' => 'Service Tax']);
    $tax->applicableTransactionCodes()->attach($room);
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->withNights()->checkedIn()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        'adults' => 2,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'amount' => '300.00']);

    return ['user' => $user, 'property' => $property, 'unit' => $unit];
}

test('a night posted late keeps the night as its service date, with its tax', function () {
    $setup = sdSetup();

    $late = app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'))->lines;
    $onTime = app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-13'))->lines;

    expect($late)->toHaveCount(2)
        ->and(collect($late)->map(fn (FolioLine $line): array => [$line->posting_date->toDateString(), $line->service_date->toDateString()])->unique()->values()->all())->toBe([['2026-10-13', '2026-10-12']])
        ->and(collect($onTime)->map(fn (FolioLine $line): array => [$line->posting_date->toDateString(), $line->service_date->toDateString()])->unique()->values()->all())->toBe([['2026-10-13', '2026-10-13']]);
});

test('a reversal and a transfer copy keep the service date of their line', function () {
    $setup = sdSetup();
    $charge = app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'))->lines[0];
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $charge->folio->reservation_id]);

    $copies = app(TransferLines::class)($setup['user'], [$charge], $master)->lines;
    $reversal = app(ReverseLine::class)($setup['user'], $copies[0])->lines;

    expect(collect($copies)->every(fn (FolioLine $line): bool => $line->service_date->toDateString() === '2026-10-12'))->toBeTrue()
        ->and(collect($reversal)->every(fn (FolioLine $line): bool => $line->service_date->toDateString() === '2026-10-12' && $line->posting_date->toDateString() === '2026-10-13'))->toBeTrue();
});

test('the database refuses a service date after the posting date', function () {
    $folio = Folio::factory()->create();

    FolioLine::factory()->create(['folio_id' => $folio->id, 'posting_date' => '2026-10-13', 'service_date' => '2026-10-13']);

    expect(fn () => FolioLine::factory()->create(['folio_id' => $folio->id, 'posting_date' => '2026-10-13', 'service_date' => '2026-10-14']))
        ->toThrow(QueryException::class);
});

test('the folio tab sorts by service date and names the posting date of a late line', function () {
    $setup = sdSetup();
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-13'));
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));

    $this->actingAs($setup['user'])
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['unit']->reservation_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('folio.rows', fn ($rows) => collect($rows)->where('state', 'posted')->map(fn ($row) => [$row['date'], $row['postedDate']])->values()->all() === [
                ['2026-10-12', '2026-10-13'], ['2026-10-12', '2026-10-13'], ['2026-10-13', null], ['2026-10-13', null],
            ]));
});
