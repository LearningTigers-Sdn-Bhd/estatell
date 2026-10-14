<?php

use App\Enums\ChargingUnit;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Services\Packages\InclusionAllocator;
use App\Services\Packages\InclusionLine;
use App\Services\Packages\InclusionSplit;
use Brick\Money\Money;

/**
 * @param  list<array<string, mixed>>  $specs
 * @return list<RatePlanInclusion>
 */
function allocatorInclusions(array $specs): array
{
    $plan = RatePlan::factory()->create();

    return array_map(
        fn (array $spec): RatePlanInclusion => RatePlanInclusion::factory()->for($plan)->create($spec),
        $specs,
    );
}

function allocateNight(string $amount, array $specs, int $guests = 2, bool $firstNight = true): InclusionSplit
{
    return app(InclusionAllocator::class)->allocate(Money::of($amount, 'MYR'), allocatorInclusions($specs), $guests, $firstNight);
}

/** @return list<string> */
function splitLines(InclusionSplit $split): array
{
    return array_map(fn (InclusionLine $line): string => $line->amount->getAmount()->__toString(), $split->lines);
}

test('RM300 with breakfast for 2 guests posts the room at RM250 and breakfast at RM50', function () {
    $split = allocateNight('300', [['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '25.00']]);

    expect($split->isRefused())->toBeFalse()
        ->and($split->room->getAmount()->__toString())->toBe('250.00')
        ->and(splitLines($split))->toBe(['50.00']);
});

test('each charging unit posts its own amount', function (ChargingUnit $unit, bool $firstNight, array $expected) {
    $split = allocateNight('500', [['charging_unit' => $unit, 'quantity' => 2, 'allocated_amount' => '10.00']], 3, $firstNight);

    expect(splitLines($split))->toBe($expected);
})->with([
    'per night, first night' => [ChargingUnit::PerNight, true, ['20.00']],
    'per night, later night' => [ChargingUnit::PerNight, false, ['20.00']],
    'per guest-night counts guests every night' => [ChargingUnit::PerGuestNight, false, ['60.00']],
    'per stay posts once' => [ChargingUnit::PerStay, true, ['20.00']],
    'per stay is not posted later' => [ChargingUnit::PerStay, false, []],
    'per item posts once' => [ChargingUnit::PerItem, true, ['20.00']],
    'per item is not posted later' => [ChargingUnit::PerItem, false, []],
    'per guest counts guests once' => [ChargingUnit::PerGuest, true, ['60.00']],
    'per guest is not posted later' => [ChargingUnit::PerGuest, false, []],
]);

test('the lines and the room always add up to the night amount', function () {
    $split = allocateNight('333.33', [
        ['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '12.34'],
        ['charging_unit' => ChargingUnit::PerStay, 'allocated_amount' => '7.77'],
    ], 3);

    $sum = array_reduce($split->lines, fn (Money $carry, InclusionLine $line): Money => $carry->plus($line->amount), $split->room);

    expect($sum->getAmount()->__toString())->toBe('333.33')
        ->and(splitLines($split))->toBe(['37.02', '7.77']);
});

test('a line keeps its inclusion and charge item, and zero amounts post nothing', function () {
    [$free, $paid] = allocatorInclusions([['allocated_amount' => '0.00'], ['allocated_amount' => '5.00']]);

    $split = app(InclusionAllocator::class)->allocate(Money::of('100', 'MYR'), [$free, $paid], 1, true);

    expect($split->lines)->toHaveCount(1)
        ->and($split->lines[0]->inclusionId)->toBe($paid->id)
        ->and($split->lines[0]->chargeItemId)->toBe($paid->charge_item_id)
        ->and($split->room->getAmount()->__toString())->toBe('95.00');
});

test('a night with no inclusions stays whole on the room', function () {
    $split = allocateNight('300', []);

    expect($split->lines)->toBe([])
        ->and($split->room->getAmount()->__toString())->toBe('300.00');
});

test('inclusions worth more than the night are refused', function () {
    $split = allocateNight('40', [['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '25.00']]);

    expect($split->isRefused())->toBeTrue()
        ->and($split->refusal)->toBe(InclusionSplit::REFUSAL_EXCEEDS_NIGHT_AMOUNT)
        ->and($split->room)->toBeNull()
        ->and($split->lines)->toBe([]);
});

test('inclusions worth exactly the night leave the room at zero', function () {
    $split = allocateNight('50', [['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '25.00']]);

    expect($split->isRefused())->toBeFalse()
        ->and($split->room->isZero())->toBeTrue();
});

test('a split needs at least one guest', function () {
    $split = allocateNight('300', [], 0);

    expect($split->refusal)->toBe(InclusionSplit::REFUSAL_GUESTS_REQUIRED);
});
