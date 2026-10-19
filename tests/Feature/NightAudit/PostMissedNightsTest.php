<?php

use App\Actions\Folio\PostingResult;
use App\Actions\NightAudit\PostMissedNights;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\UnitAddOn;

require_once __DIR__.'/../Support/PastStaySetup.php';

/**
 * A CheckedIn unit that arrived on 2026-10-08, two days before the property went live, and departs on the 16th.
 *
 * @param  array<string, mixed>  $setup
 */
function missedNightsUnit(array $setup, string $arrival = '2026-10-08')
{
    return pastStayUnit($setup, $arrival, '2026-10-16', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => $arrival.' 15:00:00']);
}

function missedNightLines(): array
{
    return FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->orderBy('service_date')->get()
        ->map(fn ($line): string => $line->service_date->toDateString().'/'.$line->posting_date->toDateString())->all();
}

test('it posts each night from go-live to the day before the business date with its own service date', function () {
    $setup = pastStaySetup();
    $unit = missedNightsUnit($setup);

    $result = app(PostMissedNights::class)($setup['user'], $unit);

    expect($result->isRefused())->toBeFalse()
        ->and(missedNightLines())->toBe(['2026-10-10/2026-10-14', '2026-10-11/2026-10-14', '2026-10-12/2026-10-14', '2026-10-13/2026-10-14']);
});

test('a rerun writes nothing new', function () {
    $setup = pastStaySetup();
    $unit = missedNightsUnit($setup, '2026-10-12');

    app(PostMissedNights::class)($setup['user'], $unit);
    $before = FolioLine::query()->count();
    $result = app(PostMissedNights::class)($setup['user'], $unit);

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toBe([])
        ->and(FolioLine::query()->count())->toBe($before);
});

test('it posts a dated add-on from before the business date and not one from before go-live', function () {
    $setup = pastStaySetup();
    $unit = missedNightsUnit($setup);
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '15.00']);
    $inRange = UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'charge_item_id' => $item->id, 'service_date' => '2026-10-11', 'unit_amount' => '15.00', 'quantity' => 1]);
    $beforeGoLive = UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'charge_item_id' => $item->id, 'service_date' => '2026-10-09', 'unit_amount' => '15.00', 'quantity' => 1]);

    app(PostMissedNights::class)($setup['user'], $unit);

    expect($inRange->refresh()->folio_line_id)->not->toBeNull()
        ->and($beforeGoLive->refresh()->folio_line_id)->toBeNull();
});

test('a unit that is not checked in is refused and nothing is posted', function () {
    $setup = pastStaySetup();
    $unit = pastStayUnit($setup, '2026-10-12', '2026-10-16');

    $result = app(PostMissedNights::class)($setup['user'], $unit);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_UNIT_NOT_POSTABLE)
        ->and(FolioLine::query()->count())->toBe(0);
});
