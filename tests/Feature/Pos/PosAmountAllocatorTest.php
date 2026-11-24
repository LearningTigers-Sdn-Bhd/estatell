<?php

use App\Services\Pos\PosAmountAllocator;
use Brick\Math\BigDecimal;

it('shares an amount in whole sen in proportion, adding up exactly', function (string $amount, array $parts, array $expected) {
    $shares = (new PosAmountAllocator)->allocate($amount, $parts);

    expect($shares)->toBe($expected)
        ->and(array_reduce($shares, fn (BigDecimal $sum, string $share) => $sum->plus($share), BigDecimal::zero())->toScale(2)->__toString())->toBe($amount);
})->with([
    'the spec room charge, RM 60 of RM 160' => ['60.00', ['137.93', '13.79', '8.28'], ['51.72', '5.17', '3.11']],
    'everything' => ['160.00', ['137.93', '13.79', '8.28'], ['137.93', '13.79', '8.28']],
    'a single part' => ['12.34', ['99.99'], ['12.34']],
    'three equal parts' => ['1.00', ['1.00', '1.00', '1.00'], ['0.34', '0.33', '0.33']],
    'one sen over three parts' => ['0.01', ['5.00', '5.00', '5.00'], ['0.01', '0.00', '0.00']],
    'a zero part gets nothing' => ['10.00', ['0.00', '5.00', '5.00'], ['0.00', '5.00', '5.00']],
]);

it('gives nothing when there is nothing to share', function () {
    expect((new PosAmountAllocator)->allocate('0.00', ['1.00', '2.00']))->toBe(['0.00', '0.00'])
        ->and((new PosAmountAllocator)->allocate('5.00', ['0.00', '0.00']))->toBe(['0.00', '0.00'])
        ->and((new PosAmountAllocator)->allocate('5.00', []))->toBe([]);
});

it('stays exact on awkward amounts', function () {
    $allocator = new PosAmountAllocator;

    foreach ([['99.99', ['33.33', '33.33', '33.34', '0.01']], ['0.07', ['1.11', '2.22', '3.33']], ['800.00', ['100.00', '0.03', '777.77', '10.10']]] as [$amount, $parts]) {
        $shares = $allocator->allocate($amount, $parts);

        expect(array_reduce($shares, fn (BigDecimal $sum, string $share) => $sum->plus($share), BigDecimal::zero())->toScale(2)->__toString())->toBe($amount);

        foreach ($shares as $index => $share) {
            expect(BigDecimal::of($share)->isGreaterThan($parts[$index]))->toBeFalse();
        }
    }
});
