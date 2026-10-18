<?php

use App\Data\Dashboard\PerformanceSummaryData;
use App\Data\Reports\ReportFilters;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Models\Company;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Services\Dashboard\PerformancePeriod;
use App\Services\Dashboard\PerformanceSummary;
use App\Services\Dashboard\ShareRounder;
use App\Services\Reports\OccupancyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/DashboardSetup.php';

/** The business date of these tests is 2026-10-12. */
function summaryFor(Property $property, string $preset = 'month', ?string $month = null): PerformanceSummaryData
{
    $period = PerformancePeriod::fromRequest($preset, $month, CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-01-01'));

    return app(PerformanceSummary::class)->forPeriod($property->fresh(), $period);
}

function summaryStatistic(Property $property, string $date, int $available, int $sold): void
{
    DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => $date, 'rooms_available' => $available, 'rooms_sold' => $sold]);
}

test('occupancy, ADR and RevPAR equal the Occupancy report totals, with closed dates and the open date', function () {
    $property = dashboardSetup()['property'];
    $roomType = RoomType::factory()->for($property)->create();
    $rooms = Room::factory()->count(4)->for($property)->create(['room_type_id' => $roomType->id]);
    $code = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();

    summaryStatistic($property, '2026-10-08', 10, 6);
    summaryStatistic($property, '2026-10-09', 10, 3);
    dashboardLine($property, $code, '600.00', '2026-10-08');
    dashboardLine($property, $code, '250.50', '2026-10-09');
    dashboardLine($property, $code, '180.00', '2026-10-12');
    $unit = dashboardUnit($property, '2026-10-12', '2026-10-13', 'checkedIn', ['room_type_id' => $roomType->id]);
    $unit->nights()->update(['room_id' => $rooms->first()->id]);

    $report = app(OccupancyReport::class);
    $rows = $report->rows($property->fresh(), new ReportFilters('2026-10-06', '2026-10-12'));
    $totals = $report->totals($rows)->figures;
    $summary = summaryFor($property, '7d');

    expect(collect($rows)->contains(fn ($row) => $row->live))->toBeTrue()
        ->and($totals['available'])->toBe(24)
        ->and($summary->hasAuditFigures)->toBeTrue()
        ->and($summary->occupancy->value)->toBe($totals['occupancy'])
        ->and($summary->adr->value)->toBe($totals['adr'])
        ->and($summary->revpar->value)->toBe($totals['revpar']);
});

test('the comparison is worked out from the sums, and hidden when the compared range has no available rooms', function () {
    $property = dashboardSetup()['property'];
    $code = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    dashboardUnit($property, '2026-10-10', '2026-10-11');
    summaryStatistic($property, '2026-10-10', 10, 5);
    dashboardLine($property, $code, '500.00', '2026-10-10');

    $hidden = summaryFor($property, '7d');

    expect($hidden->occupancy->value)->toBe('50.00')
        ->and($hidden->occupancy->change)->toBeNull()
        ->and($hidden->adr->change)->toBeNull()
        ->and($hidden->revpar->change)->toBeNull();

    summaryStatistic($property, '2026-10-03', 8, 2);
    dashboardLine($property, $code, '80.00', '2026-10-03');
    $shown = summaryFor($property, '7d');

    expect($shown->occupancy->change)->toBe('25.00')
        ->and($shown->adr->value)->toBe('100.00')
        ->and($shown->adr->change)->toBe('150.00')
        ->and($shown->revpar->change)->toBe('400.00');
});

test('a range with no stays has no figures, never zero', function () {
    $property = dashboardSetup()['property'];
    summaryStatistic($property, '2026-10-05', 10, 0);

    $summary = summaryFor($property);

    expect($summary->hasStays)->toBeFalse()
        ->and($summary->occupancy->value)->toBeNull()
        ->and($summary->adr->value)->toBeNull()
        ->and($summary->revpar->value)->toBeNull()
        ->and($summary->leadTime->average->value)->toBeNull()
        ->and($summary->leadTime->units)->toBe(0)
        ->and($summary->channelMix->nights)->toBe(0)
        ->and($summary->channelMix->bySource)->toBe([]);
});

test('a range with stays but no night audit figures has no occupancy, ADR or RevPAR, but keeps lead time and channel mix', function () {
    $property = dashboardSetup()['property'];
    dashboardUnit($property, '2026-10-02', '2026-10-04');

    $summary = summaryFor($property);

    expect($summary->hasStays)->toBeTrue()
        ->and($summary->hasAuditFigures)->toBeFalse()
        ->and($summary->occupancy->value)->toBeNull()
        ->and($summary->adr->value)->toBeNull()
        ->and($summary->revpar->value)->toBeNull()
        ->and($summary->channelMix->nights)->toBe(2)
        ->and($summary->leadTime->units)->toBe(1);
});

test('lead time leaves out walk-ins and cancelled units, reads created_at in the property time zone and counts a negative as 0', function () {
    $property = dashboardSetup()['property'];
    PropertySetting::query()->where('property_id', $property->id)->update(['time_zone' => 'Asia/Kuala_Lumpur']);
    $booked = fn (string $createdAt, array $state = []) => Reservation::factory()->state($state)->create(['property_id' => $property->id, 'created_at' => $createdAt]);

    // 17:00 UTC on the 5th is 01:00 on the 6th in Kuala Lumpur: 2 days, not 3.
    dashboardBookedUnit($property, $booked('2026-10-05 17:00:00'), '2026-10-08', '2026-10-09');
    dashboardBookedUnit($property, $booked('2026-10-12 00:00:00'), '2026-10-12', '2026-10-13');
    dashboardBookedUnit($property, $booked('2026-10-11 12:00:00'), '2026-10-10', '2026-10-11');
    dashboardBookedUnit($property, $booked('2026-09-01 12:00:00'), '2026-10-11', '2026-10-12', 'checkedIn');
    dashboardBookedUnit($property, Reservation::factory()->walkIn()->create(['property_id' => $property->id, 'created_at' => '2026-10-01 12:00:00']), '2026-10-12', '2026-10-13');
    dashboardBookedUnit($property, $booked('2026-10-01 12:00:00'), '2026-10-09', '2026-10-10', 'cancelled');

    $lead = summaryFor($property)->leadTime;

    expect($lead->units)->toBe(4)
        ->and($lead->average->value)->toBe('11')
        ->and(collect($lead->buckets)->pluck('units', 'key')->all())->toBe(['same-day' => 2, '1-7' => 1, '8-30' => 0, '31-plus' => 1])
        ->and(collect($lead->buckets)->pluck('share')->all())->toBe([50, 25, 0, 25]);
});

test('lead time compares the average with the compared range in days', function () {
    $property = dashboardSetup()['property'];
    dashboardBookedUnit($property, Reservation::factory()->create(['property_id' => $property->id, 'created_at' => '2026-10-02 12:00:00']), '2026-10-10', '2026-10-11');
    dashboardBookedUnit($property, Reservation::factory()->create(['property_id' => $property->id, 'created_at' => '2026-09-30 12:00:00']), '2026-10-03', '2026-10-04');

    $lead = summaryFor($property, '7d')->leadTime->average;

    expect($lead->value)->toBe('8')
        ->and($lead->previous)->toBe('3')
        ->and($lead->change)->toBe('5.00');
});

test('channel mix counts only live units nights in the range, adds up to 100% and shares one Booking.com row', function () {
    $property = dashboardSetup()['property'];
    $other = dashboardSetup()['property'];
    $booking = fn () => Reservation::factory()->ota('Booking.com')->create(['property_id' => $property->id]);
    $companyA = Company::factory()->create(['property_id' => $property->id, 'name' => 'Acme']);
    $companyB = Company::factory()->create(['property_id' => $property->id, 'name' => 'Borneo Travel']);

    // A manual Booking.com booking and a channel manager one use the same source row: 2 + 1 nights.
    dashboardBookedUnit($property, $booking(), '2026-10-02', '2026-10-04');
    dashboardBookedUnit($property, $booking(), '2026-10-05', '2026-10-06', 'checkedOut');
    // Direct: 1 night, and 1 of 2 nights in the range (the 13th is outside it).
    dashboardUnit($property, '2026-10-03', '2026-10-04');
    dashboardUnit($property, '2026-10-12', '2026-10-14');
    // Company / Travel agent: Acme 2 nights, Borneo Travel 1 night.
    dashboardBookedUnit($property, Reservation::factory()->company($companyA)->create(['property_id' => $property->id]), '2026-10-07', '2026-10-09');
    dashboardBookedUnit($property, Reservation::factory()->company($companyB)->create(['property_id' => $property->id]), '2026-10-07', '2026-10-08');
    // Left out: cancelled, no show, before the range, and another property.
    dashboardUnit($property, '2026-10-02', '2026-10-06', 'cancelled');
    dashboardUnit($property, '2026-09-28', '2026-09-30');
    dashboardUnit($other, '2026-10-02', '2026-10-06');

    $mix = summaryFor($property)->channelMix;

    expect($mix->nights)->toBe(8)
        ->and($mix->amount)->toBe('800.00')
        ->and(collect($mix->bySource)->pluck('name')->all())->toBe(['Booking.com', 'Company / Travel agent', 'Direct'])
        ->and(collect($mix->bySource)->pluck('nights')->all())->toBe([3, 3, 2])
        ->and(collect($mix->bySource)->sum('share'))->toBe(100)
        ->and(collect($mix->bySource)->pluck('share')->all())->toBe([38, 37, 25])
        ->and(collect($mix->bySource)->pluck('badge.name')->all())->toBe(['Booking.com', 'Company / Travel agent', 'Direct'])
        ->and(collect($mix->byType)->pluck('key')->all())->toBe(['ota', 'company', 'direct'])
        ->and(collect($mix->byType)->sum('share'))->toBe(100)
        ->and(collect($mix->byType)->pluck('badge')->filter()->all())->toBe([])
        ->and(collect($mix->bySource[1]->companies)->map(fn ($company) => [$company->name, $company->nights])->all())->toBe([['Acme', 2], ['Borneo Travel', 1]])
        ->and($mix->bySource[0]->companies)->toBe([]);
});

test('two bookings of one company add up in one entry, and another company stays apart', function () {
    $property = dashboardSetup()['property'];
    $first = Company::factory()->create(['property_id' => $property->id, 'name' => 'Acme']);
    $second = Company::factory()->create(['property_id' => $property->id, 'name' => 'Borneo']);
    dashboardBookedUnit($property, Reservation::factory()->company($first)->create(['property_id' => $property->id]), '2026-10-02', '2026-10-04');
    dashboardBookedUnit($property, Reservation::factory()->company($first)->create(['property_id' => $property->id]), '2026-10-05', '2026-10-06');
    dashboardBookedUnit($property, Reservation::factory()->company($second)->create(['property_id' => $property->id]), '2026-10-02', '2026-10-03');

    $mix = summaryFor($property)->channelMix;

    expect(collect($mix->bySource[0]->companies)->map(fn ($company) => [$company->name, $company->nights])->all())->toBe([['Acme', 3], ['Borneo', 1]])
        ->and(collect($mix->byType[0]->companies)->pluck('nights')->all())->toBe([3, 1]);
});

test('a company booking under a direct source stays in its own source', function () {
    $property = dashboardSetup()['property'];
    $company = Company::factory()->create(['property_id' => $property->id]);
    dashboardBookedUnit($property, Reservation::factory()->create(['property_id' => $property->id, 'company_id' => $company->id]), '2026-10-02', '2026-10-04');

    $mix = summaryFor($property)->channelMix;

    expect(collect($mix->bySource)->pluck('name')->all())->toBe(['Direct'])
        ->and($mix->bySource[0]->companies)->toBe([])
        ->and($mix->byType[0]->companies)->toBe([]);
});

test('the trend has one point for each day, against the same day of the compared range', function () {
    $property = dashboardSetup()['property'];
    summaryStatistic($property, '2026-10-03', 10, 5);
    summaryStatistic($property, '2026-09-03', 10, 2);

    $trend = summaryFor($property)->trend;

    expect($trend)->toHaveCount(12)
        ->and($trend[0]->date)->toBe('2026-10-01')
        ->and($trend[2]->occupancy)->toBe(50.0)
        ->and($trend[2]->previousDate)->toBe('2026-09-03')
        ->and($trend[2]->previousOccupancy)->toBe(20.0)
        ->and($trend[3]->occupancy)->toBeNull();
});

test('the 12 month trend has one point for each month', function () {
    $property = dashboardSetup()['property'];
    summaryStatistic($property, '2026-09-10', 10, 5);
    summaryStatistic($property, '2026-09-11', 10, 7);
    summaryStatistic($property, '2025-09-10', 10, 1);

    $trend = summaryFor($property, '12m')->trend;

    expect($trend)->toHaveCount(12)
        ->and($trend[0]->date)->toBe('2025-11')
        ->and($trend[0]->previousDate)->toBe('2024-11')
        ->and($trend[10]->date)->toBe('2026-09')
        ->and($trend[10]->occupancy)->toBe(60.0)
        ->and($trend[10]->previousDate)->toBe('2025-09')
        ->and($trend[10]->previousOccupancy)->toBe(10.0)
        ->and($trend[11]->date)->toBe('2026-10');
});

test('the earliest date is the earlier of the first statistic and the first night of a live unit', function () {
    $property = dashboardSetup()['property'];
    $service = app(PerformanceSummary::class);

    expect($service->earliestDate($property))->toBeNull();

    summaryStatistic($property, '2026-08-15', 10, 1);
    expect($service->earliestDate($property)->toDateString())->toBe('2026-08-15');

    dashboardUnit($property, '2026-07-20', '2026-07-22');
    dashboardUnit($property, '2026-06-01', '2026-06-03', 'cancelled');
    expect($service->earliestDate($property)->toDateString())->toBe('2026-07-20');
});

test('the summary uses the same number of queries for 7 days and 12 months', function () {
    $property = dashboardSetup([PermissionEnum::ReportsView])['property'];
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count(3)->for($property)->create(['room_type_id' => $roomType->id]);
    summaryStatistic($property, '2026-10-08', 10, 4);
    summaryStatistic($property, '2026-03-08', 10, 4);
    dashboardUnit($property, '2026-10-08', '2026-10-10', 'checkedOut', ['room_type_id' => $roomType->id]);
    dashboardUnit($property, '2026-03-08', '2026-03-10', 'checkedOut', ['room_type_id' => $roomType->id]);
    summaryFor($property, '7d');

    $count = function (string $preset) use ($property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        summaryFor($property, $preset);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count('12m'))->toBe($count('7d'));
});

test('shares are whole percents that add up to 100', function (array $counts, array $expected) {
    expect((new ShareRounder)->percentages($counts))->toBe($expected);
})->with([
    'thirds' => [[1, 1, 1], [34, 33, 33]],
    'halves of three' => [[3, 3, 2], [38, 37, 25]],
    'one row' => [[7], [100]],
    'nothing' => [[0, 0], [0, 0]],
    'a tiny row stays at zero' => [[999, 1], [100, 0]],
]);
