<?php

use App\Data\Property\CurrencyData;
use App\Models\Casts\AsCurrency;
use App\Models\PropertySetting;
use App\Services\Property\CurrencyOptions;
use Brick\Money\Currency;

test('the currency list leaves out three decimal, four decimal, fund and accounting codes', function () {
    $codes = CurrencyOptions::codes();

    expect($codes)->toContain('MYR', 'THB', 'JPY', 'USD')
        ->and($codes)->not->toContain('BHD', 'KWD', 'CLF', 'UYW', 'BOV', 'USN', 'XAD')
        ->and(CurrencyOptions::options()[array_search('MYR', $codes, true)])
        ->toBe(['value' => 'MYR', 'label' => 'Ringgit Malaysia (MYR)']);
});

test('the currency cast reads a code and writes a code', function () {
    $settings = PropertySetting::factory()->create(['currency' => 'THB']);

    expect($settings->refresh()->currency)->toBeInstanceOf(Currency::class)
        ->and($settings->currency->getCurrencyCode())->toBe('THB')
        ->and($settings->getRawOriginal('currency'))->toBe('THB');

    $settings->update(['currency' => Currency::of('JPY')]);

    expect($settings->getRawOriginal('currency'))->toBe('JPY')
        ->and(fn () => (new AsCurrency)->set($settings, 'currency', 12, []))->toThrow(InvalidArgumentException::class);
});

test('currency data carries the code, name and decimals', function () {
    expect(CurrencyData::fromCurrency(Currency::of('JPY'))->toArray())
        ->toBe(['code' => 'JPY', 'name' => 'Yen', 'decimals' => 0]);
});
