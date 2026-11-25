<?php

use App\Enums\PosDiscountType;
use App\Enums\TaxRateType;
use App\Services\Pos\Calculation\PosCalculatorContext;
use App\Services\Pos\Calculation\PosCalculatorDiscount;
use App\Services\Pos\Calculation\PosCalculatorLine;
use App\Services\Pos\Calculation\PosCalculatorModifier;
use App\Services\Pos\Calculation\PosCalculatorResult;
use App\Services\Pos\Calculation\PosCalculatorTax;
use App\Services\Pos\PosOrderCalculator;
use Brick\Math\BigDecimal;

function posSst(string $rate = '6.00'): PosCalculatorTax
{
    return new PosCalculatorTax(1, 10, 'SST', 'SST', '02', TaxRateType::Percent, $rate);
}

function posTourismFlat(string $rate = '10.00', bool $foreignOnly = true): PosCalculatorTax
{
    return new PosCalculatorTax(2, 11, 'TTX', 'Tourism tax', '03', TaxRateType::Flat, $rate, $foreignOnly);
}

function posHeritage(string $rate = '2.00'): PosCalculatorTax
{
    return new PosCalculatorTax(3, 12, 'HERITAGE', 'Heritage levy', '06', TaxRateType::Percent, $rate);
}

/** @param list<PosCalculatorTax> $taxes */
function posLine(int $key, string $price, array $taxes, bool $includesTax = false, int $quantity = 1, array $overrides = []): PosCalculatorLine
{
    return new PosCalculatorLine(...array_merge(['key' => $key, 'unitPrice' => $price, 'quantity' => $quantity, 'priceIncludesTax' => $includesTax, 'taxes' => $taxes], $overrides));
}

function posDineIn(array $overrides = []): PosCalculatorContext
{
    return new PosCalculatorContext(...array_merge(['applyServiceCharge' => true, 'serviceChargeRate' => '10.00'], $overrides));
}

function posCalc(array $lines, ?PosCalculatorContext $context = null): PosCalculatorResult
{
    return (new PosOrderCalculator)->calculate($lines, $context ?? posDineIn());
}

it('prices the ++ worked example: RM 100.00 food, 10% service charge, 6% SST is RM 116.00', function () {
    $result = posCalc([posLine(1, '100.00', [posSst()])]);
    $line = $result->lines[0];

    expect($result->isRefused())->toBeFalse()
        ->and($line->netAmount)->toBe('100.00')
        ->and($line->serviceChargeAmount)->toBe('10.00')
        ->and($line->taxTotal)->toBe('6.00')
        ->and($line->taxes[0]->taxableBase)->toBe('100.00')
        ->and($line->taxes[0]->inclusive)->toBeFalse()
        ->and($result->subtotal)->toBe('100.00')
        ->and($result->serviceChargeTotal)->toBe('10.00')
        ->and($result->taxTotal)->toBe('6.00')
        ->and($result->grandTotal)->toBe('116.00');
});

it('prices the nett worked example: RM 116.00 displayed holds RM 100.00, RM 10.00 and RM 6.00', function () {
    $result = posCalc([posLine(1, '116.00', [posSst()], true)]);
    $line = $result->lines[0];

    expect($line->netAmount)->toBe('100.00')
        ->and($line->serviceChargeAmount)->toBe('10.00')
        ->and($line->taxTotal)->toBe('6.00')
        ->and($line->taxes[0]->inclusive)->toBeTrue()
        ->and($result->grandTotal)->toBe('116.00');
});

it('adds service charge to the SST base only when the dining option says so', function () {
    $taxable = posCalc([posLine(1, '100.00', [posSst()])], posDineIn(['serviceChargeTaxable' => true]));

    expect($taxable->lines[0]->taxes[0]->taxableBase)->toBe('110.00')
        ->and($taxable->lines[0]->taxTotal)->toBe('6.60')
        ->and($taxable->grandTotal)->toBe('116.60');

    $nett = posCalc([posLine(1, '116.60', [posSst()], true)], posDineIn(['serviceChargeTaxable' => true]));
    expect($nett->lines[0]->netAmount)->toBe('100.00')->and($nett->lines[0]->serviceChargeAmount)->toBe('10.00')->and($nett->lines[0]->taxTotal)->toBe('6.60')->and($nett->grandTotal)->toBe('116.60');
});

it('charges no service charge without the option, or on an exempt item', function () {
    $none = posCalc([posLine(1, '100.00', [posSst()])], new PosCalculatorContext);
    $exempt = posCalc([posLine(1, '100.00', [posSst()], false, 1, ['serviceChargeExempt' => true])]);

    expect($none->serviceChargeTotal)->toBe('0.00')->and($none->grandTotal)->toBe('106.00')
        ->and($exempt->serviceChargeTotal)->toBe('0.00')->and($exempt->grandTotal)->toBe('106.00');
});

it('splits every price from RM 0.01 to RM 100.00 exactly, with no negative part', function (array $taxes, string $serviceCharge, bool $taxable) {
    $calculator = new PosOrderCalculator;
    $context = new PosCalculatorContext(applyServiceCharge: $serviceCharge !== '0', serviceChargeRate: $serviceCharge, serviceChargeTaxable: $taxable);

    for ($sen = 1; $sen <= 10000; $sen++) {
        $price = BigDecimal::of($sen)->dividedBy(100, 2)->__toString();
        $result = $calculator->calculate([posLine(1, $price, $taxes, true)], $context);
        $line = $result->lines[0];

        $parts = BigDecimal::of($line->netAmount)->plus($line->serviceChargeAmount)->plus($line->taxTotal)->__toString();
        $taxSum = array_reduce($line->taxes, fn (BigDecimal $total, $tax) => $total->plus($tax->amount), BigDecimal::zero())->toScale(2)->__toString();

        expect($parts)->toBe($price, "price {$price}")
            ->and($result->grandTotal)->toBe($price, "grand total {$price}")
            ->and($taxSum)->toBe($line->taxTotal, "tax sum {$price}");

        expect(BigDecimal::of($line->netAmount)->isNegative())->toBeFalse("net {$price}")
            ->and(BigDecimal::of($line->serviceChargeAmount)->isNegative())->toBeFalse("service charge {$price}");

        foreach ($line->taxes as $tax) {
            expect(BigDecimal::of($tax->amount)->isNegative())->toBeFalse("tax {$price}");
        }
    }
})->with([
    'single SST, 10% service charge' => [fn () => [posSst()], '10.00', false],
    'single SST, no service charge' => [fn () => [posSst()], '0', false],
    'two percentage taxes' => [fn () => [posSst(), posHeritage()], '10.00', false],
    'two percentage taxes, taxable service charge' => [fn () => [posSst('8.00'), posHeritage('2.00')], '10.00', true],
    'three percentage taxes' => [fn () => [posSst(), posHeritage(), new PosCalculatorTax(4, 13, 'X', 'Other', '06', TaxRateType::Percent, '1.50')], '5.00', false],
    'no tax, service charge only' => [fn () => [], '10.00', false],
]);

it('takes a flat tax out of a nett price first and refuses when it exceeds the price', function () {
    $context = new PosCalculatorContext(guestIsForeign: true);
    $result = posCalc([posLine(1, '13.00', [posTourismFlat('10.00'), posSst()], true, 2)], $context);
    $line = $result->lines[0];

    expect($result->isRefused())->toBeFalse()
        ->and($line->taxes[0]->amount)->toBe('20.00')
        ->and($line->taxes[1]->amount)->toBe('0.34')
        ->and($line->netAmount)->toBe('5.66')
        ->and($result->grandTotal)->toBe('26.00');

    expect(posCalc([posLine(1, '9.99', [posTourismFlat('10.00')], true)], $context)->refusal)->toBe('inclusive_tax_exceeds_amount');
});

it('adds a flat tax per unit on a ++ price', function () {
    $result = posCalc([posLine(1, '50.00', [posTourismFlat('10.00'), posSst()], false, 3)], new PosCalculatorContext(guestIsForeign: true));

    expect($result->lines[0]->taxes[0]->amount)->toBe('30.00')
        ->and($result->lines[0]->taxes[1]->amount)->toBe('9.00')
        ->and($result->grandTotal)->toBe('189.00');
});

it('applies a foreign guests only tax to foreign guests, skips it for locals and asks when unknown', function () {
    $line = fn () => [posLine(1, '100.00', [posTourismFlat('10.00'), posSst()])];

    $foreign = posCalc($line(), new PosCalculatorContext(guestIsForeign: true));
    $local = posCalc($line(), new PosCalculatorContext(guestIsForeign: false));
    $unknown = posCalc($line(), new PosCalculatorContext);

    expect($foreign->grandTotal)->toBe('116.00')->and(count($foreign->lines[0]->taxes))->toBe(2)
        ->and($local->grandTotal)->toBe('106.00')->and(count($local->lines[0]->taxes))->toBe(1)
        ->and($unknown->refusal)->toBe('nationality_required');

    expect(posCalc([posLine(1, '0.00', [posTourismFlat('10.00')])], new PosCalculatorContext)->isRefused())->toBeFalse();
});

it('refuses SST when no SST registration number is on file, but not other taxes or a free line', function () {
    $noNumber = new PosCalculatorContext(sstRegistered: false);

    expect(posCalc([posLine(1, '100.00', [posSst()])], $noNumber)->refusal)->toBe('sst_registration_missing')
        ->and(posCalc([posLine(1, '100.00', [posHeritage()])], $noNumber)->isRefused())->toBeFalse()
        ->and(posCalc([posLine(1, '0.00', [posSst()])], $noNumber)->isRefused())->toBeFalse()
        ->and(posCalc([posLine(1, '100.00', [posSst()])], new PosCalculatorContext(sstRegistered: true))->isRefused())->toBeFalse();
});

it('adds the packaging fee as its own service-charge-exempt line with its own taxes', function () {
    $context = posDineIn(['packagingFee' => '1.00', 'packagingTaxes' => [posSst()]]);
    $result = posCalc([posLine(1, '100.00', [posSst()])], $context);
    $packaging = $result->lines[1];

    expect($packaging->isPackagingFee)->toBeTrue()
        ->and($packaging->key)->toBe(PosOrderCalculator::PACKAGING_KEY)
        ->and($packaging->netAmount)->toBe('1.00')
        ->and($packaging->serviceChargeAmount)->toBe('0.00')
        ->and($packaging->taxTotal)->toBe('0.06')
        ->and($result->subtotal)->toBe('101.00')
        ->and($result->serviceChargeTotal)->toBe('10.00')
        ->and($result->taxTotal)->toBe('6.06')
        ->and($result->grandTotal)->toBe('117.06');
});

it('covers an entitled meal and bills only the extra item: RM 16.24', function () {
    $result = posCalc([
        posLine(1, '25.00', [posSst()], false, 2, ['isEntitlementCovered' => true]),
        posLine(2, '14.00', [posSst()]),
    ]);

    expect($result->lines[0]->payableBase)->toBe('0.00')
        ->and($result->lines[0]->lineTotal)->toBe('0.00')
        ->and($result->lines[1]->serviceChargeAmount)->toBe('1.40')
        ->and($result->lines[1]->taxTotal)->toBe('0.84')
        ->and($result->grandTotal)->toBe('16.24');
});

it('bills a paid upgrade on an entitled meal: RM 3.48', function () {
    $result = posCalc([posLine(1, '25.00', [posSst()], false, 1, ['isEntitlementCovered' => true, 'modifiers' => [new PosCalculatorModifier('3.00')]])]);

    expect($result->lines[0]->lineGross)->toBe('3.00')
        ->and($result->lines[0]->serviceChargeAmount)->toBe('0.30')
        ->and($result->lines[0]->taxTotal)->toBe('0.18')
        ->and($result->grandTotal)->toBe('3.48');
});

it('multiplies modifiers by the line quantity', function () {
    $result = posCalc([posLine(1, '10.00', [], false, 3, ['modifiers' => [new PosCalculatorModifier('1.50', 2)]])], new PosCalculatorContext);

    expect($result->lines[0]->lineGross)->toBe('39.00')->and($result->grandTotal)->toBe('39.00');
});

it('works out every item discount type before service charge and tax', function (PosDiscountType $type, string $value, string $expectedDiscount, string $expectedGrand) {
    $result = posCalc([posLine(1, '100.00', [posSst()], false, 1, ['discount' => new PosCalculatorDiscount($type, $value)])]);

    expect($result->lines[0]->itemDiscount)->toBe($expectedDiscount)->and($result->grandTotal)->toBe($expectedGrand);
})->with([
    'fixed percent' => [PosDiscountType::FixedPercent, '10', '10.00', '104.40'],
    'variable percent' => [PosDiscountType::VariablePercent, '12.50', '12.50', '101.50'],
    'fixed amount' => [PosDiscountType::FixedAmount, '20.00', '20.00', '92.80'],
    'variable amount' => [PosDiscountType::VariableAmount, '0.01', '0.01', '115.99'],
    'fixed price' => [PosDiscountType::FixedPrice, '60.00', '40.00', '69.60'],
    'free of charge' => [PosDiscountType::Foc, '0', '100.00', '0.00'],
    'amount over the price is capped' => [PosDiscountType::FixedAmount, '500.00', '100.00', '0.00'],
    'percent over 100 is capped' => [PosDiscountType::VariablePercent, '150', '100.00', '0.00'],
    'fixed price above the price discounts nothing' => [PosDiscountType::FixedPrice, '150.00', '0.00', '116.00'],
]);

it('discounts add-ons too unless they are excluded', function () {
    $modifiers = [new PosCalculatorModifier('10.00')];
    $discount = new PosCalculatorDiscount(PosDiscountType::FixedPercent, '10');
    $with = posCalc([posLine(1, '90.00', [], false, 1, ['modifiers' => $modifiers, 'discount' => $discount])], new PosCalculatorContext);
    $without = posCalc([posLine(1, '90.00', [], false, 1, ['modifiers' => $modifiers, 'discount' => $discount, 'excludeAddonsFromDiscount' => true])], new PosCalculatorContext);

    expect($with->lines[0]->itemDiscount)->toBe('10.00')->and($with->grandTotal)->toBe('90.00')
        ->and($without->lines[0]->itemDiscount)->toBe('9.00')->and($without->grandTotal)->toBe('91.00');
});

it('takes an order discount off before service charge and tax', function () {
    $result = posCalc([posLine(1, '100.00', [posSst()])], posDineIn(['orderDiscount' => new PosCalculatorDiscount(PosDiscountType::FixedPercent, '10')]));

    expect($result->lines[0]->orderDiscountShare)->toBe('10.00')
        ->and($result->lines[0]->netAmount)->toBe('90.00')
        ->and($result->serviceChargeTotal)->toBe('9.00')
        ->and($result->taxTotal)->toBe('5.40')
        ->and($result->grandTotal)->toBe('104.40');
});

it('shares an order discount in whole sen by largest remainder, never below zero on any line', function () {
    $lines = array_map(fn (int $key): PosCalculatorLine => posLine($key, '0.01', []), [1, 2, 3, 4]);
    $result = posCalc($lines, new PosCalculatorContext(orderDiscount: new PosCalculatorDiscount(PosDiscountType::VariableAmount, '0.02')));

    expect(array_map(fn ($line): string => $line->orderDiscountShare, $result->lines))->toBe(['0.01', '0.01', '0.00', '0.00'])
        ->and(array_map(fn ($line): string => $line->payableBase, $result->lines))->toBe(['0.00', '0.00', '0.01', '0.01'])
        ->and($result->grandTotal)->toBe('0.02');
});

it('shares an order discount so the shares add up and no line goes negative', function () {
    $prices = ['3.33', '3.33', '3.34', '10.00', '0.07', '19.99'];
    $lines = array_map(fn (string $price, int $i): PosCalculatorLine => posLine($i + 1, $price, [posSst()]), $prices, array_keys($prices));

    foreach (['0.01', '1.00', '7.77', '12.34', '39.91', '40.00', '99.00'] as $discount) {
        $result = posCalc($lines, posDineIn(['orderDiscount' => new PosCalculatorDiscount(PosDiscountType::VariableAmount, $discount)]));
        $shares = array_map(fn ($line): string => $line->orderDiscountShare, $result->lines);
        $total = array_reduce($shares, fn (BigDecimal $sum, string $share) => $sum->plus($share), BigDecimal::zero())->__toString();

        $expected = BigDecimal::of($discount)->isGreaterThan('40.06') ? '40.06' : $discount;

        expect($total)->toBe(BigDecimal::of($expected)->toScale(2)->__toString(), "discount {$discount}");

        foreach ($result->lines as $line) {
            expect(BigDecimal::of($line->payableBase)->isNegative())->toBeFalse("payable {$discount}")
                ->and(BigDecimal::of($line->orderDiscountShare)->isNegative())->toBeFalse("share {$discount}");
        }
    }
});

it('leaves entitled lines and the packaging fee out of the order discount', function () {
    $result = posCalc(
        [posLine(1, '25.00', [], false, 1, ['isEntitlementCovered' => true]), posLine(2, '20.00', [])],
        new PosCalculatorContext(packagingFee: '1.00', orderDiscount: new PosCalculatorDiscount(PosDiscountType::FixedPercent, '50')),
    );

    expect($result->lines[0]->orderDiscountShare)->toBe('0.00')
        ->and($result->lines[1]->orderDiscountShare)->toBe('10.00')
        ->and($result->lines[2]->orderDiscountShare)->toBe('0.00')
        ->and($result->grandTotal)->toBe('11.00');
});

it('prices the same whatever the tax rows say about being inclusive', function () {
    // The calculator never sees `taxes.is_inclusive`; only the item decides. Same inputs, same answer, in both modes.
    $exclusive = posCalc([posLine(1, '100.00', [posSst()], false)]);
    $nett = posCalc([posLine(1, '116.00', [posSst()], true)]);

    expect($exclusive->grandTotal)->toBe($nett->grandTotal)
        ->and($exclusive->lines[0]->netAmount)->toBe($nett->lines[0]->netAmount)
        ->and($exclusive->lines[0]->taxTotal)->toBe($nett->lines[0]->taxTotal);
});

it('adds up lines, quantities and several taxes into the order totals', function () {
    $result = posCalc([
        posLine(1, '12.90', [posSst()], false, 3),
        posLine(2, '8.50', [posSst(), posHeritage()], true, 2),
    ]);

    $gross = BigDecimal::of($result->subtotal)->plus($result->serviceChargeTotal)->plus($result->taxTotal)->__toString();

    expect($gross)->toBe($result->grandTotal)
        ->and(BigDecimal::of($result->lines[0]->lineTotal)->plus($result->lines[1]->lineTotal)->__toString())->toBe($result->grandTotal)
        ->and($result->lines[1]->lineTotal)->toBe('17.00');
});
