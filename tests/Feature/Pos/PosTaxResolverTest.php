<?php

use App\Enums\PosDiscountType;
use App\Enums\TaxRateType;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Services\Pos\Calculation\PosCalculatorContext;
use App\Services\Pos\Calculation\PosCalculatorLine;
use App\Services\Pos\PosOrderCalculator;
use App\Services\Pos\PosTaxResolver;

/** @return array{property: Property, code: TransactionCode} */
function posResolverSetup(): array
{
    $property = Property::factory()->create();

    return ['property' => $property, 'code' => TransactionCode::factory()->create(['property_id' => $property->id])];
}

it('resolves the enabled taxes of a transaction code, with their LHDN type', function () {
    $s = posResolverSetup();
    $sst = Tax::factory()->for($s['property'])->create(['code' => 'SST', 'rate' => '6.00']);
    $custom = Tax::factory()->for($s['property'])->create(['code' => 'FNB', 'lhdn_tax_type' => '02', 'rate' => '8.00']);
    $levy = Tax::factory()->for($s['property'])->create(['code' => 'HERITAGE', 'rate' => '2.00']);
    $tourism = Tax::factory()->for($s['property'])->create(['code' => 'TTX', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00', 'foreign_guests_only' => true]);
    $off = Tax::factory()->for($s['property'])->create(['code' => 'OFF', 'enabled' => false]);
    $elsewhere = Tax::factory()->for($s['property'])->create(['code' => 'ELSEWHERE']);

    foreach ([$sst, $custom, $levy, $tourism, $off] as $tax) {
        $tax->applicableTransactionCodes()->attach($s['code']);
    }

    $taxes = app(PosTaxResolver::class)->forTransactionCode($s['property'], $s['code']->id);

    expect(array_column($taxes, 'code'))->toBe(['SST', 'FNB', 'HERITAGE', 'TTX'])
        ->and(array_column($taxes, 'lhdnTaxType'))->toBe(['02', '02', null, '03'])
        ->and($taxes[3]->rateType)->toBe(TaxRateType::Flat)
        ->and($taxes[3]->foreignGuestsOnly)->toBeTrue()
        ->and($taxes[0]->transactionCodeId)->toBe($sst->transaction_code_id)
        ->and(array_column($taxes, 'code'))->not->toContain('ELSEWHERE');
});

it('ignores the property of another hotel', function () {
    $s = posResolverSetup();
    $other = posResolverSetup();
    Tax::factory()->for($other['property'])->create(['code' => 'SST'])->applicableTransactionCodes()->attach($other['code']);

    expect(app(PosTaxResolver::class)->forTransactionCode($s['property'], $other['code']->id))->toBe([]);
});

it('prices the same across all four combinations of item price mode and tax row inclusive flag', function () {
    $totals = [];

    foreach ([false, true] as $itemIncludesTax) {
        foreach ([false, true] as $taxRowInclusive) {
            $s = posResolverSetup();
            Tax::factory()->for($s['property'])->create(['code' => 'SST', 'rate' => '6.00', 'is_inclusive' => $taxRowInclusive])->applicableTransactionCodes()->attach($s['code']);
            $taxes = app(PosTaxResolver::class)->forTransactionCode($s['property'], $s['code']->id);
            $price = $itemIncludesTax ? '116.00' : '100.00';

            $result = app(PosOrderCalculator::class)->calculate(
                [new PosCalculatorLine(1, $price, 1, $itemIncludesTax, $taxes)],
                new PosCalculatorContext(applyServiceCharge: true, serviceChargeRate: '10.00'),
            );

            $totals[] = [$result->lines[0]->netAmount, $result->lines[0]->serviceChargeAmount, $result->lines[0]->taxTotal, $result->grandTotal];
        }
    }

    expect(array_unique($totals, SORT_REGULAR))->toBe([['100.00', '10.00', '6.00', '116.00']]);
});

it('finds the SST registration number from the outlet, then the e-invoice setting, then an SST tax', function () {
    $s = posResolverSetup();
    $outlet = PosOutlet::factory()->create(['property_id' => $s['property']->id]);
    $resolver = app(PosTaxResolver::class);

    expect($resolver->sstRegistrationNumber($outlet, $s['property']))->toBe('');

    $tax = Tax::factory()->for($s['property'])->create(['code' => 'SST', 'registration_number' => 'W10-111']);
    expect($resolver->sstRegistrationNumber($outlet, $s['property']))->toBe('W10-111');

    $setting = new PropertyEInvoiceSetting(['supplier_sst_registration' => 'W10-222']);
    $setting->property_id = $s['property']->id;
    $setting->save();
    expect($resolver->sstRegistrationNumber($outlet, $s['property']->fresh()))->toBe('W10-222');

    $outlet->forceFill(['sst_registration_number' => ' W10-333 '])->save();
    expect($resolver->sstRegistrationNumber($outlet, $s['property']->fresh()))->toBe('W10-333');

    $tax->delete();
});

it('refuses an SST sale for an outlet with no registration number', function () {
    $s = posResolverSetup();
    Tax::factory()->for($s['property'])->create(['code' => 'SST', 'rate' => '6.00'])->applicableTransactionCodes()->attach($s['code']);
    $outlet = PosOutlet::factory()->create(['property_id' => $s['property']->id]);
    $resolver = app(PosTaxResolver::class);
    $taxes = $resolver->forTransactionCode($s['property'], $s['code']->id);

    $result = app(PosOrderCalculator::class)->calculate(
        [new PosCalculatorLine(1, '10.00', 1, false, $taxes)],
        new PosCalculatorContext(sstRegistered: $resolver->sstRegistrationNumber($outlet, $s['property']) !== ''),
    );

    expect($result->refusal)->toBe('sst_registration_missing');
    expect(PosDiscountType::Foc->needsApproval())->toBeTrue();
});
