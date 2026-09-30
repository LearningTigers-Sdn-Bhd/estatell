<?php

use App\Enums\AgeBandPricing;
use App\Enums\PricingBasis;
use App\Enums\StayDiscountType;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RateOccupancyPrice;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use App\Models\RatePlanStayDiscount;
use App\Models\RoomType;
use App\Services\Rates\RateQuote;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use Illuminate\Support\Facades\Date;

/**
 * @param  array<string, mixed>  $planAttributes
 * @return array{0: Property, 1: RatePlan, 2: RoomType}
 */
function quoteSetup(array $planAttributes = [], bool $bed = false): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create($planAttributes);
    $roomType = $bed
        ? RoomType::factory()->bed()->for($property)->create()
        : RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    return [$property, $plan, $roomType];
}

function quoteRate(RatePlan $plan, RoomType $roomType, string $date, ?string $amount = '200.00'): Rate
{
    return Rate::factory()->create([
        'rate_plan_id' => $plan->id,
        'room_type_id' => $roomType->id,
        'date' => $date,
        'amount' => $amount,
    ]);
}

/** @param list<int> $childAges */
function quoteFor(RatePlan $plan, RoomType $roomType, int $adults = 2, array $childAges = [], int $beds = 1, string $arrival = '2026-11-02', string $departure = '2026-11-03'): RateQuote
{
    return app(RateQuoteService::class)->quote(new RateQuoteRequest(
        $plan->fresh(),
        $roomType,
        Date::parse($arrival),
        Date::parse($departure),
        $adults,
        $childAges,
        $beds,
    ));
}

function quoteTotal(RateQuote $quote): string
{
    return (string) $quote->total->getAmount();
}

test('a per room stay sums the room price for every night', function () {
    [, $plan, $roomType] = quoteSetup();
    quoteRate($plan, $roomType, '2026-11-02', '200.00');
    quoteRate($plan, $roomType, '2026-11-03', '250.00');

    $quote = quoteFor($plan, $roomType, departure: '2026-11-04');

    expect($quote->isRefused())->toBeFalse()
        ->and($quote->nights)->toHaveCount(2)
        ->and(quoteTotal($quote))->toBe('450.00')
        ->and((string) $quote->discountAmount->getAmount())->toBe('0.00');
});

test('a per room stay charges extra adults above the base occupancy and a flat fee per child', function () {
    [, $plan, $roomType] = quoteSetup(['extra_adult_amount' => '40.00', 'extra_child_amount' => '25.00']);
    quoteRate($plan, $roomType, '2026-11-02', '200.00');
    PropertyAgeGroup::factory()->for($plan->property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);

    $quote = quoteFor($plan, $roomType, adults: 3, childAges: [6]);

    expect(quoteTotal($quote))->toBe('265.00')
        ->and((string) $quote->nights[0]->base->getAmount())->toBe('200.00')
        ->and((string) $quote->nights[0]->extra->getAmount())->toBe('65.00');
});

test('a per room occupancy price replaces the room price and extra adults but children still pay', function () {
    [, $plan, $roomType] = quoteSetup(['extra_adult_amount' => '40.00', 'extra_child_amount' => '25.00']);
    $rate = quoteRate($plan, $roomType, '2026-11-02', '200.00');
    RateOccupancyPrice::factory()->for($rate)->create(['adults' => 3, 'amount' => '280.00']);
    PropertyAgeGroup::factory()->for($plan->property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);

    expect(quoteTotal(quoteFor($plan, $roomType, adults: 3)))->toBe('280.00')
        ->and(quoteTotal(quoteFor($plan, $roomType, adults: 3, childAges: [6])))->toBe('305.00')
        ->and(quoteTotal(quoteFor($plan, $roomType, adults: 5)))->toBe('320.00');
});

test('a per guest stay prices the doc example with children by age group', function () {
    [$property, $plan, $roomType] = quoteSetup(['pricing_basis' => PricingBasis::PerGuest]);
    $rate = quoteRate($plan, $roomType, '2026-11-02', '400.00');
    RateOccupancyPrice::factory()->for($rate)->create(['adults' => 2, 'amount' => '600.00']);
    $infant = PropertyAgeGroup::factory()->for($property)->create(['label' => 'Infant', 'min_age' => 0, 'max_age' => 3]);
    $child = PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 4, 'max_age' => 11]);
    RatePlanChildPrice::factory()->for($plan)->for($infant, 'ageGroup')->create(['pricing' => AgeBandPricing::Free]);
    RatePlanChildPrice::factory()->for($plan)->for($child, 'ageGroup')->create(['pricing' => AgeBandPricing::Percent, 'value' => '50.00']);

    $quote = quoteFor($plan, $roomType, adults: 2, childAges: [2, 8]);

    expect(quoteTotal($quote))->toBe('800.00')
        ->and((string) $quote->nights[0]->base->getAmount())->toBe('600.00')
        ->and((string) $quote->nights[0]->extra->getAmount())->toBe('200.00');
});

test('a per guest fixed child price and the single supplement apply', function () {
    [$property, $plan, $roomType] = quoteSetup(['pricing_basis' => PricingBasis::PerGuest, 'single_supplement' => '30.00']);
    quoteRate($plan, $roomType, '2026-11-02', '100.00');
    $child = PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);
    RatePlanChildPrice::factory()->for($plan)->for($child, 'ageGroup')->create(['pricing' => AgeBandPricing::Fixed, 'value' => '35.00']);

    expect(quoteTotal(quoteFor($plan, $roomType, adults: 1)))->toBe('130.00')
        ->and(quoteTotal(quoteFor($plan, $roomType, adults: 2)))->toBe('200.00')
        ->and(quoteTotal(quoteFor($plan, $roomType, adults: 2, childAges: [5])))->toBe('235.00');
});

test('a per guest percent child price rounds half up to two decimals', function () {
    [$property, $plan, $roomType] = quoteSetup(['pricing_basis' => PricingBasis::PerGuest]);
    quoteRate($plan, $roomType, '2026-11-02', '100.05');
    $child = PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);
    RatePlanChildPrice::factory()->for($plan)->for($child, 'ageGroup')->create(['pricing' => AgeBandPricing::Percent, 'value' => '50.00']);

    $quote = quoteFor($plan, $roomType, adults: 1, childAges: [5]);

    expect((string) $quote->nights[0]->extra->getAmount())->toBe('50.03');
});

test('a child past the last age group counts as an adult', function () {
    [$property, $plan, $roomType] = quoteSetup(['pricing_basis' => PricingBasis::PerGuest]);
    quoteRate($plan, $roomType, '2026-11-02', '100.00');
    PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);

    expect(quoteTotal(quoteFor($plan, $roomType, adults: 1, childAges: [15])))->toBe('200.00');
});

test('a per guest stay is refused when an age group has no child price', function () {
    [$property, $plan, $roomType] = quoteSetup(['pricing_basis' => PricingBasis::PerGuest]);
    quoteRate($plan, $roomType, '2026-11-02');
    PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);

    $quote = quoteFor($plan, $roomType, adults: 2, childAges: [5]);

    expect($quote->isRefused())->toBeTrue()
        ->and($quote->refusal)->toBe(RateQuote::REFUSAL_CHILD_PRICE_MISSING);
});

test('a per bed stay multiplies the bed rate by the beds', function () {
    [, $plan, $roomType] = quoteSetup(['pricing_basis' => PricingBasis::PerBed], bed: true);
    quoteRate($plan, $roomType, '2026-11-02', '45.00');
    quoteRate($plan, $roomType, '2026-11-03', '45.00');

    expect(quoteTotal(quoteFor($plan, $roomType, adults: 1, beds: 3, departure: '2026-11-04')))->toBe('270.00');
});

test('a night without a price refuses the quote', function () {
    [, $plan, $roomType] = quoteSetup();
    quoteRate($plan, $roomType, '2026-11-02');

    $quote = quoteFor($plan, $roomType, departure: '2026-11-04');

    expect($quote->refusal)->toBe(RateQuote::REFUSAL_RATE_MISSING)
        ->and($quote->refusalDate->toDateString())->toBe('2026-11-03');
});

test('an archived plan, an unlinked room type and a package plan are refused', function () {
    [$property, $plan, $roomType] = quoteSetup();
    quoteRate($plan, $roomType, '2026-11-02');
    $other = RoomType::factory()->for($property)->create();
    $package = RatePlan::factory()->for($property)->create(['pricing_basis' => PricingBasis::Package]);
    $package->roomTypes()->attach($roomType);

    expect(quoteFor($plan, $other)->refusal)->toBe(RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD)
        ->and(quoteFor($package, $roomType)->refusal)->toBe(RateQuote::REFUSAL_BASIS_UNSUPPORTED);

    $plan->update(['archived_at' => now()]);

    expect(quoteFor($plan, $roomType)->refusal)->toBe(RateQuote::REFUSAL_PLAN_ARCHIVED);
});

test('a stay with no nights, no adults or no beds is refused', function () {
    [, $plan, $roomType] = quoteSetup();
    quoteRate($plan, $roomType, '2026-11-02');

    expect(quoteFor($plan, $roomType, departure: '2026-11-02')->refusal)->toBe(RateQuote::REFUSAL_INVALID_STAY)
        ->and(quoteFor($plan, $roomType, adults: 0)->refusal)->toBe(RateQuote::REFUSAL_ADULTS_REQUIRED)
        ->and(quoteFor($plan, $roomType, beds: 0)->refusal)->toBe(RateQuote::REFUSAL_BEDS_REQUIRED);
});

test('another room type or plan price does not leak into the quote', function () {
    [$property, $plan, $roomType] = quoteSetup();
    $otherPlan = RatePlan::factory()->for($property)->create();
    quoteRate($otherPlan, $roomType, '2026-11-02', '999.00');

    expect(quoteFor($plan, $roomType)->refusal)->toBe(RateQuote::REFUSAL_RATE_MISSING);
});

/** @param array<string, mixed> $attributes */
function stayDiscount(RatePlan $plan, array $attributes): RatePlanStayDiscount
{
    return RatePlanStayDiscount::factory()->for($plan)->create($attributes);
}

test('a long-stay percent discount from night 2 keeps the first night at full price', function () {
    [, $plan, $roomType] = quoteSetup();
    foreach (['02', '03', '04'] as $day) {
        quoteRate($plan, $roomType, "2026-11-{$day}", '200.00');
    }
    stayDiscount($plan, ['min_nights' => 3, 'discount_type' => StayDiscountType::Percent, 'value' => '20.00', 'from_night' => 2]);

    $quote = quoteFor($plan, $roomType, departure: '2026-11-05');

    expect(quoteTotal($quote))->toBe('520.00')
        ->and((string) $quote->discountAmount->getAmount())->toBe('80.00')
        ->and((string) $quote->nights[0]->discountAmount->getAmount())->toBe('0.00')
        ->and((string) $quote->nights[1]->discountAmount->getAmount())->toBe('40.00');
});

test('the longest qualifying long-stay rule wins and a shorter stay gets none', function () {
    [, $plan, $roomType] = quoteSetup();
    foreach (['02', '03', '04', '05', '06'] as $day) {
        quoteRate($plan, $roomType, "2026-11-{$day}", '100.00');
    }
    stayDiscount($plan, ['min_nights' => 2, 'value' => '10.00']);
    stayDiscount($plan, ['min_nights' => 4, 'value' => '20.00']);

    expect(quoteTotal(quoteFor($plan, $roomType, departure: '2026-11-07')))->toBe('400.00')
        ->and(quoteTotal(quoteFor($plan, $roomType, departure: '2026-11-04')))->toBe('180.00')
        ->and(quoteTotal(quoteFor($plan, $roomType, departure: '2026-11-03')))->toBe('100.00');
});

test('a percent discount is taken off the whole night including extras', function () {
    [, $plan, $roomType] = quoteSetup(['extra_adult_amount' => '50.00']);
    quoteRate($plan, $roomType, '2026-11-02', '200.00');
    quoteRate($plan, $roomType, '2026-11-03', '200.00');
    stayDiscount($plan, ['min_nights' => 2, 'value' => '10.00']);

    expect(quoteTotal(quoteFor($plan, $roomType, adults: 3, departure: '2026-11-04')))->toBe('450.00');
});

test('an amount discount is per room, per guest or per bed by pricing basis', function () {
    [, $room, $roomType] = quoteSetup();
    quoteRate($room, $roomType, '2026-11-02', '100.00');
    quoteRate($room, $roomType, '2026-11-03', '100.00');
    stayDiscount($room, ['min_nights' => 2, 'discount_type' => StayDiscountType::Amount, 'value' => '15.00']);

    [$property, $guest, $guestRoomType] = quoteSetup(['pricing_basis' => PricingBasis::PerGuest]);
    quoteRate($guest, $guestRoomType, '2026-11-02', '100.00');
    quoteRate($guest, $guestRoomType, '2026-11-03', '100.00');
    stayDiscount($guest, ['min_nights' => 2, 'discount_type' => StayDiscountType::Amount, 'value' => '15.00']);
    $child = PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 0, 'max_age' => 11]);
    RatePlanChildPrice::factory()->for($guest)->for($child, 'ageGroup')->create(['pricing' => AgeBandPricing::Free]);

    [, $bed, $bedRoomType] = quoteSetup(['pricing_basis' => PricingBasis::PerBed], bed: true);
    quoteRate($bed, $bedRoomType, '2026-11-02', '50.00');
    quoteRate($bed, $bedRoomType, '2026-11-03', '50.00');
    stayDiscount($bed, ['min_nights' => 2, 'discount_type' => StayDiscountType::Amount, 'value' => '15.00']);

    expect(quoteTotal(quoteFor($room, $roomType, departure: '2026-11-04')))->toBe('170.00')
        ->and(quoteTotal(quoteFor($guest, $guestRoomType, adults: 2, childAges: [5], departure: '2026-11-04')))->toBe('310.00')
        ->and(quoteTotal(quoteFor($bed, $bedRoomType, adults: 1, beds: 2, departure: '2026-11-04')))->toBe('140.00');
});

test('a night never goes below zero after the discount', function () {
    [, $plan, $roomType] = quoteSetup();
    quoteRate($plan, $roomType, '2026-11-02', '30.00');
    quoteRate($plan, $roomType, '2026-11-03', '30.00');
    stayDiscount($plan, ['min_nights' => 2, 'discount_type' => StayDiscountType::Amount, 'value' => '100.00']);

    $quote = quoteFor($plan, $roomType, departure: '2026-11-04');

    expect(quoteTotal($quote))->toBe('0.00')
        ->and((string) $quote->discountAmount->getAmount())->toBe('60.00');
});
