<?php

require_once __DIR__.'/Support/DayUseSetup.php';

use App\Models\Rate;
use App\Models\RoomType;
use App\Services\Rates\DayUseOffers;
use App\Services\Rates\RateQuote;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;

test('quoteDayUse returns the hour price of the date', function () {
    $setup = dayUseSetup();

    $quote = app(RateQuoteService::class)->quoteDayUse($setup['roomType'], $setup['plan'], $setup['date'], 4);

    expect($quote->isRefused())->toBeFalse()
        ->and((string) $quote->amount->getAmount())->toBe('100.00');
});

test('quoteDayUse refuses a missing price, hours outside the range, stop sell, an archived plan and a room type not sold', function () {
    $setup = dayUseSetup();
    $service = app(RateQuoteService::class);
    $date = $setup['date'];
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', $date->addDay())->first()->hourPrices()->where('hours', 3)->delete();
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', $date->addDays(2))->update(['stop_sell' => true]);

    expect($service->quoteDayUse($setup['roomType'], $setup['plan'], $date->addDay(), 3)->refusal)->toBe(RateQuote::REFUSAL_NO_HOUR_PRICE)
        ->and($service->quoteDayUse($setup['roomType'], $setup['plan'], $date->addDays(20), 3)->refusal)->toBe(RateQuote::REFUSAL_NO_HOUR_PRICE)
        ->and($service->quoteDayUse($setup['roomType'], $setup['plan'], $date, 7)->refusal)->toBe(RateQuote::REFUSAL_HOURS_NOT_SOLD)
        ->and($service->quoteDayUse($setup['roomType'], $setup['plan'], $date, 1)->refusal)->toBe(RateQuote::REFUSAL_HOURS_NOT_SOLD)
        ->and($service->quoteDayUse($setup['roomType'], $setup['plan'], $date->addDays(2), 4)->refusal)->toBe(RateQuote::REFUSAL_RESTRICTED)
        ->and($service->quoteDayUse($setup['roomType'], $setup['plan'], $date->addDays(2), 4, ignoreStopSell: true)->isRefused())->toBeFalse()
        ->and($service->quoteDayUse($setup['roomType'], $setup['basic'], $date, 4)->refusal)->toBe(RateQuote::REFUSAL_PLAN_NOT_DAY_USE);

    $setup['plan']->forceFill(['archived_at' => now()])->save();

    expect($service->quoteDayUse($setup['roomType'], $setup['plan'], $date, 4)->refusal)->toBe(RateQuote::REFUSAL_PLAN_ARCHIVED);

    $setup['plan']->forceFill(['archived_at' => null])->save();
    $other = RoomType::factory()->for($setup['property'])->create();

    expect($service->quoteDayUse($other, $setup['plan']->refresh(), $date, 4)->refusal)->toBe(RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD);
});

test('min stay, max stay and closed to arrival do not apply to a day use', function () {
    $setup = dayUseSetup();
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->update(['min_stay' => 3, 'max_stay' => 5, 'closed_to_arrival' => true, 'closed_to_departure' => true]);

    expect(app(RateQuoteService::class)->quoteDayUse($setup['roomType'], $setup['plan'], $setup['date'], 4)->isRefused())->toBeFalse();
});

test('a nightly quote still refuses the day use plan', function () {
    $setup = dayUseSetup();

    $quote = app(RateQuoteService::class)->quote(new RateQuoteRequest($setup['plan'], $setup['roomType'], $setup['date'], $setup['date']->addDay(), 2, [], 1));

    expect($quote->refusal)->toBe(RateQuote::REFUSAL_PLAN_NOT_NIGHTLY);
});

test('the offers list the priced hours that fit the window with their end times', function () {
    $setup = dayUseSetup();
    $offers = app(DayUseOffers::class);
    $at = fn (string $start) => array_map(fn ($offer) => [$offer->hours, $offer->amount, $offer->endsAt], $offers->forStart($setup['plan'], $setup['roomType'], $setup['date'], $start));

    expect($at('10:00'))->toBe([[2, '60.00', '12:00'], [3, '80.00', '13:00'], [4, '100.00', '14:00'], [5, '120.00', '15:00'], [6, '140.00', '16:00']])
        ->and($at('14:00'))->toBe([[2, '60.00', '16:00'], [3, '80.00', '17:00'], [4, '100.00', '18:00']])
        ->and($at('16:30'))->toBe([])
        ->and($at('07:30'))->toBe([])
        ->and($at('10:15'))->toBe([]);

    Rate::query()->where('rate_plan_id', $setup['plan']->id)->whereDate('date', $setup['date'])->first()->hourPrices()->where('hours', 3)->delete();

    expect(array_column($at('10:00'), 0))->toBe([2, 4, 5, 6])
        ->and($offers->forStart($setup['plan'], $setup['roomType'], $setup['date']->addDays(20), '10:00'))->toBe([])
        ->and($offers->forStart($setup['basic'], $setup['roomType'], $setup['date'], '10:00'))->toBe([]);
});

test('a plan with no window sells any block that ends on the same day', function () {
    $setup = dayUseSetup();
    $setup['plan']->forceFill(['day_use_from' => null, 'day_use_until' => null])->save();
    $offers = app(DayUseOffers::class)->forStart($setup['plan']->refresh(), $setup['roomType'], $setup['date'], '20:00');

    expect(array_column(array_map(fn ($offer) => (array) $offer, $offers), 'hours'))->toBe([2, 3])
        ->and(DayUseOffers::endsAt('22:00', 2))->toBeNull()
        ->and(DayUseOffers::endsAt('09:30', 3))->toBe('12:30:00');
});
