<?php

use App\Services\Property\AmountInWords;
use Brick\Money\Currency;

test('an amount is spelled in English words', function (string $amount, string $words) {
    expect(app(AmountInWords::class)->for($amount, Currency::of('MYR')))->toBe($words);
})->with([
    'whole amount' => ['1504.00', 'Ringgit Malaysia one thousand five hundred and four only'],
    'with cents' => ['1504.50', 'Ringgit Malaysia one thousand five hundred and four and fifty sen only'],
    'cents only' => ['0.05', 'Five sen only'],
    'round number' => ['100000', 'Ringgit Malaysia one hundred thousand only'],
    'hyphen' => ['21', 'Ringgit Malaysia twenty-one only'],
]);

test('an amount in another currency splits by the decimals of the currency', function (string $amount, string $code, string $words) {
    expect(app(AmountInWords::class)->for($amount, Currency::of($code)))->toBe($words);
})->with([
    'baht and satang' => ['12.25', 'THB', 'Baht twelve and twenty-five satang only'],
    'dollars and cents' => ['3.05', 'USD', 'US Dollar three and five cents only'],
    'no minor unit name' => ['7.50', 'CHF', 'Swiss Franc seven and 50/100 only'],
    'whole yen' => ['1500', 'JPY', 'Yen one thousand five hundred only'],
    'yen rounds to whole' => ['1500.60', 'JPY', 'Yen one thousand five hundred and one only'],
]);
