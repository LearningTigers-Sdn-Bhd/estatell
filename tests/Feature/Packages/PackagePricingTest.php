<?php

use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\Weekday;
use App\Models\PackagePrice;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use App\Models\RatePlanInclusion;
use App\Models\RoomType;
use App\Services\Packages\InclusionAllocator;
use App\Services\Rates\Pricing\StayPricing;
use App\Services\Rates\RateQuote;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use Illuminate\Support\Facades\Date;

/**
 * A package on a Room room type with one price for arrivals in June 2026 (Monday 1 Jun to Tuesday 30 Jun).
 *
 * @param  array<string, mixed>  $planAttributes
 * @return array{0: Property, 1: RatePlan, 2: RoomType}
 */
function packageQuoteSetup(PackageUnit $unit = PackageUnit::PerGuest, int $nights = 2, string $amount = '600.00', bool $bed = false, array $planAttributes = []): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->package($unit, $nights)->create($planAttributes);
    $roomType = $bed
        ? RoomType::factory()->bed()->for($property)->create()
        : RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);
    PackagePrice::factory()->for($plan)->create([
        'room_type_id' => $roomType->id,
        'arrival_from' => '2026-06-01',
        'arrival_to' => '2026-06-30',
        'amount' => $amount,
    ]);

    return [$property, $plan, $roomType];
}

/** @param list<int> $childAges */
function packageQuote(RatePlan $plan, RoomType $roomType, string $arrival = '2026-06-10', ?string $departure = null, int $adults = 2, array $childAges = [], int $beds = 1): RateQuote
{
    $plan = $plan->fresh();
    $arrivalDate = Date::parse($arrival);

    return app(RateQuoteService::class)->quote(new RateQuoteRequest(
        $plan,
        $roomType,
        $arrivalDate,
        $departure === null ? $arrivalDate->addDays($plan->fixed_nights) : Date::parse($departure),
        $adults,
        $childAges,
        $beds,
    ));
}

/** @return list<string> */
function packageNightTotals(RateQuote $quote): array
{
    return array_map(fn ($night): string => (string) $night->total()->getAmount(), $quote->nights);
}

function packageChildPrice(Property $property, RatePlan $plan, AgeBandPricing $pricing, ?string $value, int $minAge = 4, int $maxAge = 11): PropertyAgeGroup
{
    $group = PropertyAgeGroup::factory()->for($property)->create(['label' => "Age {$minAge}", 'min_age' => $minAge, 'max_age' => $maxAge]);
    RatePlanChildPrice::factory()->for($plan)->for($group, 'ageGroup')->create(['pricing' => $pricing, 'value' => $value]);

    return $group;
}

test('RM600 per adult with 2 adults and a child at 50% is RM1,500, stored as RM750 + RM750', function () {
    [$property, $plan, $roomType] = packageQuoteSetup();
    packageChildPrice($property, $plan, AgeBandPricing::Percent, '50.00');

    $quote = packageQuote($plan, $roomType, childAges: [8]);

    expect($quote->isRefused())->toBeFalse()
        ->and((string) $quote->total->getAmount())->toBe('1500.00')
        ->and(packageNightTotals($quote))->toBe(['750.00', '750.00'])
        ->and((string) $quote->nights[0]->base->getAmount())->toBe('600.00')
        ->and((string) $quote->nights[0]->extra->getAmount())->toBe('150.00')
        ->and($quote->nights[0]->date->toDateString())->toBe('2026-06-10')
        ->and($quote->nights[1]->date->toDateString())->toBe('2026-06-11');
});

test('RM500 over 3 nights splits into 166.67, 166.67 and 166.66', function () {
    [, $plan, $roomType] = packageQuoteSetup(PackageUnit::PerRoom, nights: 3, amount: '500.00');

    $quote = packageQuote($plan, $roomType);

    expect(packageNightTotals($quote))->toBe(['166.67', '166.67', '166.66'])
        ->and((string) $quote->total->getAmount())->toBe('500.00');
});

test('the Dive Package puts its one-off diving on the first night so every night covers its inclusions', function () {
    [, $plan, $roomType] = packageQuoteSetup(nights: 3, amount: '880.00');
    foreach (['20.00', '30.00', '50.00'] as $meal) {
        RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => $meal]);
    }
    RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerGuest, 'quantity' => 2, 'allocated_amount' => '180.00']);

    $quote = packageQuote($plan, $roomType);
    $firstNight = app(InclusionAllocator::class)->allocate($quote->nights[0]->total(), $plan->inclusions()->get(), 2, true);

    expect(packageNightTotals($quote))->toBe(['1066.67', '346.67', '346.66'])
        ->and((string) $quote->total->getAmount())->toBe('1760.00')
        ->and($firstNight->isRefused())->toBeFalse()
        ->and((string) $firstNight->room?->getAmount())->toBe('146.67');
});

test('a package with inclusions splits the children part by the same night totals', function () {
    [$property, $plan, $roomType] = packageQuoteSetup(amount: '300.00');
    packageChildPrice($property, $plan, AgeBandPricing::Fixed, '100.00');
    RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerStay, 'allocated_amount' => '200.00']);

    $quote = packageQuote($plan, $roomType, adults: 1, childAges: [8]);

    expect(array_map(fn ($night): array => [(string) $night->base->getAmount(), (string) $night->extra->getAmount()], $quote->nights))
        ->toBe([['225.00', '75.00'], ['75.00', '25.00']]);
});

test('a package priced below its inclusions is refused', function () {
    [, $plan, $roomType] = packageQuoteSetup(amount: '100.00');
    RatePlanInclusion::factory()->for($plan)->create(['charging_unit' => ChargingUnit::PerGuest, 'allocated_amount' => '150.00']);

    expect(packageQuote($plan, $roomType)->refusal)->toBe(RateQuote::REFUSAL_PACKAGE_BELOW_INCLUSIONS);
});

test('the adults part and the children part are each split so the cents add up', function () {
    [$property, $plan, $roomType] = packageQuoteSetup(nights: 3, amount: '100.00');
    packageChildPrice($property, $plan, AgeBandPricing::Fixed, '10.00');

    $quote = packageQuote($plan, $roomType, adults: 1, childAges: [5]);

    expect(array_map(fn ($night): string => (string) $night->base->getAmount(), $quote->nights))->toBe(['33.34', '33.33', '33.33'])
        ->and(array_map(fn ($night): string => (string) $night->extra->getAmount(), $quote->nights))->toBe(['3.34', '3.33', '3.33'])
        ->and((string) $quote->total->getAmount())->toBe('110.00');
});

test('a per-room package adds extra adults above the base occupancy and extra children for the stay', function () {
    [$property, $plan, $roomType] = packageQuoteSetup(PackageUnit::PerRoom, amount: '600.00', planAttributes: ['extra_adult_amount' => '80.00', 'extra_child_amount' => '40.00']);
    PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);

    expect((string) packageQuote($plan, $roomType)->total->getAmount())->toBe('600.00')
        ->and((string) packageQuote($plan, $roomType, adults: 3)->total->getAmount())->toBe('680.00')
        ->and((string) packageQuote($plan, $roomType, adults: 3, childAges: [6, 9])->total->getAmount())->toBe('760.00');
});

test('a per-guest package prices a child Free, by Percent of the adult price or by a Fixed amount for the stay', function (AgeBandPricing $pricing, ?string $value, string $expected) {
    [$property, $plan, $roomType] = packageQuoteSetup();
    packageChildPrice($property, $plan, $pricing, $value);

    expect((string) packageQuote($plan, $roomType, adults: 1, childAges: [5])->total->getAmount())->toBe($expected);
})->with([
    'free' => [AgeBandPricing::Free, null, '600.00'],
    'percent' => [AgeBandPricing::Percent, '25.00', '750.00'],
    'fixed per stay' => [AgeBandPricing::Fixed, '90.00', '690.00'],
]);

test('a child with no price for the age group refuses the quote', function () {
    [$property, $plan, $roomType] = packageQuoteSetup();
    PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);

    $quote = packageQuote($plan, $roomType, childAges: [5]);

    expect($quote->refusal)->toBe(RateQuote::REFUSAL_CHILD_PRICE_MISSING)
        ->and($quote->refusalDate->toDateString())->toBe('2026-06-10');
});

test('a bed room type pays the price for each bed, and children and extras do not apply', function (PackageUnit $unit) {
    [, $plan, $roomType] = packageQuoteSetup($unit, amount: '250.00', bed: true, planAttributes: ['extra_adult_amount' => '80.00', 'extra_child_amount' => '40.00']);

    expect((string) packageQuote($plan, $roomType, adults: 1, beds: 3)->total->getAmount())->toBe('750.00')
        ->and((string) packageQuote($plan, $roomType, adults: 4, childAges: [5], beds: 1)->total->getAmount())->toBe('250.00');
})->with([PackageUnit::PerGuest, PackageUnit::PerRoom]);

test('the price comes from the period the arrival date falls in, both ends included', function () {
    [, $plan, $roomType] = packageQuoteSetup();
    PackagePrice::factory()->for($plan)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-07-01', 'arrival_to' => '2026-07-31', 'amount' => '750.00']);

    expect((string) packageQuote($plan, $roomType, '2026-06-01')->total->getAmount())->toBe('1200.00')
        ->and((string) packageQuote($plan, $roomType, '2026-06-30')->total->getAmount())->toBe('1200.00')
        ->and((string) packageQuote($plan, $roomType, '2026-07-01')->total->getAmount())->toBe('1500.00')
        ->and((string) packageQuote($plan, $roomType, '2026-07-31')->total->getAmount())->toBe('1500.00');
});

test('an arrival outside every period, or only priced for another room type, is refused on the arrival date', function () {
    [$property, $plan, $roomType] = packageQuoteSetup();
    $other = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($other);

    $early = packageQuote($plan, $roomType, '2026-05-31');
    $otherRoomType = packageQuote($plan, $other);

    expect($early->refusal)->toBe(RateQuote::REFUSAL_PACKAGE_PRICE_MISSING)
        ->and($early->refusalDate->toDateString())->toBe('2026-05-31')
        ->and($otherRoomType->refusal)->toBe(RateQuote::REFUSAL_PACKAGE_PRICE_MISSING);
});

test('a stay that is not the fixed number of nights is refused', function (string $departure) {
    [, $plan, $roomType] = packageQuoteSetup(nights: 2);

    expect(packageQuote($plan, $roomType, '2026-06-10', $departure)->refusal)->toBe(RateQuote::REFUSAL_STAY_LENGTH_MISMATCH);
})->with(['one night' => '2026-06-11', 'three nights' => '2026-06-13']);

test('an archived package and a room type the package does not sell are refused', function () {
    [$property, $plan, $roomType] = packageQuoteSetup();
    $other = RoomType::factory()->for($property)->create();

    expect(packageQuote($plan, $other)->refusal)->toBe(RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD);

    $plan->update(['archived_at' => now()]);

    expect(packageQuote($plan, $roomType)->refusal)->toBe(RateQuote::REFUSAL_PLAN_ARCHIVED);
});

test('rates rows that only carry restrictions do not get in the way, and no discount applies', function () {
    [, $plan, $roomType] = packageQuoteSetup();
    Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => '2026-06-10', 'amount' => null, 'min_stay' => 2]);

    $quote = packageQuote($plan, $roomType);

    expect($quote->isRefused())->toBeFalse()
        ->and((string) $quote->total->getAmount())->toBe('1200.00')
        ->and($quote->discountAmount->isZero())->toBeTrue();
});

test('an edited package price changes the next quote only', function () {
    [, $plan, $roomType] = packageQuoteSetup();
    $before = packageQuote($plan, $roomType);

    PackagePrice::query()->where('rate_plan_id', $plan->id)->update(['amount' => '700.00']);

    expect((string) $before->total->getAmount())->toBe('1200.00')
        ->and((string) packageQuote($plan, $roomType)->total->getAmount())->toBe('1400.00');
});

test('every nightly pricing basis has a pricing strategy', function (PricingBasis $basis) {
    expect(is_subclass_of($basis->strategy(), StayPricing::class))->toBeTrue();
})->with(array_values(array_filter(PricingBasis::cases(), fn (PricingBasis $basis): bool => $basis !== PricingBasis::DayUse)));

test('the arrival weekday of a package is checked as a restriction', function (string $arrival, bool $allowed) {
    [, $plan, $roomType] = packageQuoteSetup();
    $plan->arrivalWeekdays()->create(['weekday' => Weekday::Friday]);
    $plan->arrivalWeekdays()->create(['weekday' => Weekday::Saturday]);

    $violations = app(RestrictionCheck::class)->violations($plan->id, $roomType->id, Date::parse($arrival), Date::parse($arrival)->addDays(2));

    expect($violations === [])->toBe($allowed);

    if (! $allowed) {
        expect($violations)->toBe(['Arrival on Monday is not allowed. Allowed days: Friday, Saturday.']);
    }
})->with([
    'a friday' => ['2026-06-05', true],
    'a saturday' => ['2026-06-06', true],
    'a monday' => ['2026-06-01', false],
]);

test('a plan with no arrival weekdays can start on any day', function () {
    [, $plan, $roomType] = packageQuoteSetup();

    expect(app(RestrictionCheck::class)->violations($plan->id, $roomType->id, Date::parse('2026-06-01'), Date::parse('2026-06-03')))->toBe([]);
});

test('every date maps to its weekday', function () {
    $monday = Date::parse('2026-06-01');

    foreach (Weekday::cases() as $offset => $weekday) {
        expect(Weekday::forDate($monday->addDays($offset)))->toBe($weekday);
    }
});
