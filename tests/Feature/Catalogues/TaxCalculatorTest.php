<?php

use App\Enums\TaxRateType;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Services\Catalogues\TaxCalculator;
use Brick\Money\Money;

/** @param array<string, mixed> $attributes */
function calculatorTax(Property $property, TransactionCode $sourceCode, array $attributes): Tax
{
    $tax = Tax::factory()->for($property)->create($attributes);
    $tax->applicableTransactionCodes()->attach($sourceCode);

    return $tax;
}

/** @return array{Property, TransactionCode, TaxCalculator} */
function taxCalculatorContext(): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    $sourceCode = TransactionCode::factory()->for($property)->create();

    return [$property, $sourceCode, app(TaxCalculator::class)];
}

test('exclusive percentage and flat taxes use the shared net basis and quantity', function () {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    calculatorTax($property, $sourceCode, ['name' => 'SST', 'code' => 'SST', 'rate_type' => TaxRateType::Percent, 'rate' => '8.00']);
    calculatorTax($property, $sourceCode, ['name' => 'Tourism tax', 'code' => 'TTX', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00']);

    $result = $calculator->calculate($property, $sourceCode, Money::of('100.00', 'MYR'), '3', 'SG');

    expect($result->isCalculated())->toBeTrue()
        ->and($result->netAmount->isEqualTo(Money::of('100.00', 'MYR')))->toBeTrue()
        ->and($result->taxes[0]->amount->isEqualTo(Money::of('8.00', 'MYR')))->toBeTrue()
        ->and($result->taxes[1]->amount->isEqualTo(Money::of('30.00', 'MYR')))->toBeTrue()
        ->and($result->grossAmount->isEqualTo(Money::of('138.00', 'MYR')))->toBeTrue();
});

test('combined inclusive percentages are extracted together without compounding', function () {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    calculatorTax($property, $sourceCode, ['name' => 'First', 'code' => 'FIRST', 'rate_type' => TaxRateType::Percent, 'rate' => '8.00', 'is_inclusive' => true]);
    calculatorTax($property, $sourceCode, ['name' => 'Second', 'code' => 'SECOND', 'rate_type' => TaxRateType::Percent, 'rate' => '4.00', 'is_inclusive' => true]);

    $result = $calculator->calculate($property, $sourceCode, Money::of('112.00', 'MYR'), '1', 'MY');

    expect($result->netAmount->isEqualTo(Money::of('100.00', 'MYR')))->toBeTrue()
        ->and($result->taxes[0]->amount->isEqualTo(Money::of('8.00', 'MYR')))->toBeTrue()
        ->and($result->taxes[1]->amount->isEqualTo(Money::of('4.00', 'MYR')))->toBeTrue()
        ->and($result->grossAmount->isEqualTo(Money::of('112.00', 'MYR')))->toBeTrue();
});

test('exclusive taxes use the net extracted from inclusive taxes', function () {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    calculatorTax($property, $sourceCode, ['name' => 'Inclusive', 'code' => 'INCLUSIVE', 'rate_type' => TaxRateType::Percent, 'rate' => '8.00', 'is_inclusive' => true]);
    calculatorTax($property, $sourceCode, ['name' => 'Exclusive', 'code' => 'EXCLUSIVE', 'rate_type' => TaxRateType::Percent, 'rate' => '6.00']);

    $result = $calculator->calculate($property, $sourceCode, Money::of('108.00', 'MYR'), '1', 'MY');

    expect($result->netAmount->isEqualTo(Money::of('100.00', 'MYR')))->toBeTrue()
        ->and($result->taxes[0]->amount->isEqualTo(Money::of('8.00', 'MYR')))->toBeTrue()
        ->and($result->taxes[1]->amount->isEqualTo(Money::of('6.00', 'MYR')))->toBeTrue()
        ->and($result->grossAmount->isEqualTo(Money::of('114.00', 'MYR')))->toBeTrue();
});

test('inclusive rounding is reconciled against the last percentage tax', function () {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    calculatorTax($property, $sourceCode, ['name' => 'Seven', 'code' => 'SEVEN', 'rate_type' => TaxRateType::Percent, 'rate' => '7.00', 'is_inclusive' => true]);
    calculatorTax($property, $sourceCode, ['name' => 'Five', 'code' => 'FIVE', 'rate_type' => TaxRateType::Percent, 'rate' => '5.00', 'is_inclusive' => true]);

    $result = $calculator->calculate($property, $sourceCode, Money::of('10.00', 'MYR'), '1', 'MY');

    expect($result->netAmount->isEqualTo(Money::of('8.93', 'MYR')))->toBeTrue()
        ->and($result->taxes[0]->amount->isEqualTo(Money::of('0.63', 'MYR')))->toBeTrue()
        ->and($result->taxes[1]->amount->isEqualTo(Money::of('0.44', 'MYR')))->toBeTrue();
});

test('foreign-only taxes refuse unknown nationality skip Malaysians and apply to foreigners', function () {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    calculatorTax($property, $sourceCode, ['name' => 'Tourism tax', 'code' => 'TTX', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00', 'foreign_guests_only' => true]);

    $unknown = $calculator->calculate($property, $sourceCode, Money::of('100.00', 'MYR'), '1', null);
    $malaysian = $calculator->calculate($property, $sourceCode, Money::of('100.00', 'MYR'), '1', 'my');
    $foreign = $calculator->calculate($property, $sourceCode, Money::of('100.00', 'MYR'), '1', 'sg');

    expect($unknown->isCalculated())->toBeFalse()
        ->and($unknown->refusalReason)->toBe(TaxCalculator::REFUSAL_NATIONALITY_REQUIRED)
        ->and($unknown->netAmount)->toBeNull()
        ->and($malaysian->taxes)->toBe([])
        ->and($malaysian->grossAmount->isEqualTo(Money::of('100.00', 'MYR')))->toBeTrue()
        ->and($foreign->taxes)->toHaveCount(1)
        ->and($foreign->grossAmount->isEqualTo(Money::of('110.00', 'MYR')))->toBeTrue();
});

test('disabled and non-matching taxes do not apply', function () {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    calculatorTax($property, $sourceCode, ['name' => 'Disabled', 'code' => 'DISABLED', 'enabled' => false]);
    $otherCode = TransactionCode::factory()->for($property)->create();
    calculatorTax($property, $otherCode, ['name' => 'Other', 'code' => 'OTHER']);

    $result = $calculator->calculate($property, $sourceCode, Money::of('100.00', 'MYR'), '1', 'MY');

    expect($result->taxes)->toBe([])
        ->and($result->netAmount->isEqualTo(Money::of('100.00', 'MYR')))->toBeTrue()
        ->and($result->grossAmount->isEqualTo(Money::of('100.00', 'MYR')))->toBeTrue();
});

test('inclusive flat taxes cannot exceed the entered amount', function () {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    calculatorTax($property, $sourceCode, ['name' => 'Flat', 'code' => 'FLAT', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00', 'is_inclusive' => true]);

    $result = $calculator->calculate($property, $sourceCode, Money::of('20.00', 'MYR'), '3', 'MY');

    expect($result->isCalculated())->toBeFalse()
        ->and($result->refusalReason)->toBe(TaxCalculator::REFUSAL_INCLUSIVE_TAX_EXCEEDS_AMOUNT);
});

test('calculator rejects invalid source currency and quantity contexts', function (string $case) {
    [$property, $sourceCode, $calculator] = taxCalculatorContext();
    $otherCode = TransactionCode::factory()->create();

    $call = match ($case) {
        'source' => fn () => $calculator->calculate($property, $otherCode, Money::of('10.00', 'MYR'), '1', 'MY'),
        'currency' => fn () => $calculator->calculate($property, $sourceCode, Money::of('10.00', 'USD'), '1', 'MY'),
        'quantity' => fn () => $calculator->calculate($property, $sourceCode, Money::of('10.00', 'MYR'), '0', 'MY'),
    };

    expect($call)->toThrow(InvalidArgumentException::class);
})->with(['source', 'currency', 'quantity']);
