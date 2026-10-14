<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Actions\Catalogues\UpdateDayUseTaxes;
use App\Enums\ChargingUnit;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Services\Folio\ScheduledFolioRows;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled blocks of the reservation, each as `[code, date, [key => amount]]`.
 *
 * @return list<array{0: string, 1: string, 2: array<string, string>}>
 */
function scheduledBlocks(array $setup, Reservation $reservation): array
{
    $unit = $reservation->units()->sole();
    $blocks = app(ScheduledFolioRows::class)->forReservation($setup['property']->refresh(), $reservation->load('booker'), [$unit->id => '101']);

    return array_map(fn (array $block): array => [
        $block['code']->code,
        $block['date'],
        collect($block['rows'])->mapWithKeys(fn ($row): array => [$row->key => $row->amount])->all(),
    ], $blocks);
}

test('a Confirmed day use unit shows a Scheduled DAY_USE row with its price and the DAY_USE taxes', function () {
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $setup['property']->taxes()->update(['enabled' => true]);
    $sst = $setup['property']->taxes()->where('code', 'SST')->sole();
    app(UpdateDayUseTaxes::class)($setup['property'], $setup['user'], [$sst->id]);
    $reservation = createDayUseReservation($setup, [dayUseLine($setup)])->reservation;
    $unit = $reservation->units()->sole();

    $blocks = scheduledBlocks($setup, $reservation);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0][0])->toBe('DAY_USE')
        ->and($blocks[0][1])->toBe($setup['date']->toDateString())
        ->and($blocks[0][2])->toBe(['dayuse:'.$unit->id => '100.00', 'dayuse:'.$unit->id.':tax:'.$sst->id => '8.00']);

    $rows = app(ScheduledFolioRows::class)->forReservation($setup['property'], $reservation->load('booker'), [$unit->id => '101'])[0]['rows'];

    expect($rows[0]->description)->toBe('Day use · 10:00–14:00')
        ->and($rows[0]->tags[0]->label)->toBe('Scheduled, not posted yet');
});

test('without taxes on DAY_USE the row is the price alone, and the Tourism tax is not charged', function () {
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $setup['property']->taxes()->update(['enabled' => true]);
    $reservation = createDayUseReservation($setup, [dayUseLine($setup)])->reservation;
    $unit = $reservation->units()->sole();

    expect(scheduledBlocks($setup, $reservation)[0][2])->toBe(['dayuse:'.$unit->id => '100.00']);
});

test('the plan inclusions are split from the price as one night', function () {
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $lunch = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '30.00', 'charging_unit' => ChargingUnit::PerItem]);
    $setup['plan']->inclusions()->create([
        'charge_item_id' => $lunch->id, 'charging_unit' => ChargingUnit::PerGuest, 'quantity' => 1, 'allocated_amount' => '15.00',
        'starts_on' => InclusionStart::ArrivalDay, 'ends_on' => InclusionEnd::DepartureDay,
    ]);
    $reservation = createDayUseReservation($setup, [dayUseLine($setup)])->reservation;
    $unit = $reservation->units()->sole();

    $blocks = scheduledBlocks($setup, $reservation);
    $inclusion = $setup['plan']->inclusions()->sole();

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0][2])->toBe(['dayuse:'.$unit->id => '70.00'])
        ->and($blocks[1][2])->toBe(['dayuse:'.$unit->id.':incl:'.$inclusion->id => '30.00']);
});

test('the row disappears once a folio line with its key exists', function () {
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $reservation = createDayUseReservation($setup, [dayUseLine($setup)])->reservation;
    $unit = $reservation->units()->sole();
    $folio = Folio::factory()->create(['property_id' => $setup['property']->id, 'reservation_id' => $reservation->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'idempotency_key' => 'dayuse:'.$unit->id]);

    expect(scheduledBlocks($setup, $reservation))->toBe([]);
});

test('a property without the DAY_USE code shows no row and does not fail', function () {
    $setup = dayUseHttpSetup();
    $reservation = createDayUseReservation($setup, [dayUseLine($setup)])->reservation;
    DB::table('transaction_codes')->where('system_key', 'day_use_revenue')->delete();

    expect(scheduledBlocks($setup, $reservation))->toBe([])
        ->and(TransactionCode::query()->where('system_key', 'day_use_revenue')->exists())->toBeFalse()
        ->and($reservation->units()->sole()->stay_type->value)->toBe('day_use');
});
