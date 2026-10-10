<?php

use App\Enums\Currency;
use App\Services\Property\AmountInWords;

test('an amount is spelled in English words', function (string $amount, string $words) {
    expect(app(AmountInWords::class)->for($amount, Currency::Myr))->toBe($words);
})->with([
    'whole amount' => ['1504.00', 'Ringgit Malaysia one thousand five hundred and four only'],
    'with cents' => ['1504.50', 'Ringgit Malaysia one thousand five hundred and four and fifty sen only'],
    'cents only' => ['0.05', 'Five sen only'],
    'round number' => ['100000', 'Ringgit Malaysia one hundred thousand only'],
    'hyphen' => ['21', 'Ringgit Malaysia twenty-one only'],
]);
