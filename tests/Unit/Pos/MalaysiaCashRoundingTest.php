<?php

use App\Enums\PosRoundingMode;
use App\Enums\PosRoundingPolicy;
use App\Services\Pos\MalaysiaCashRounding;

it('rounds to the nearest 5 sen the BNM way, across every last digit', function (string $last, string $adjustment) {
    foreach (['0.0', '10.0', '99.9', '123.4'] as $prefix) {
        $amount = $prefix.$last;
        $amount = number_format((float) $amount, 2, '.', '');

        expect(app(MalaysiaCashRounding::class)->adjustment(PosRoundingMode::FiveSen, PosRoundingPolicy::CashLegOnly, $amount, $amount))->toBe($adjustment, $amount);
    }
})->with([
    'ends 0' => ['0', '0.00'],
    'ends 1' => ['1', '-0.01'],
    'ends 2' => ['2', '-0.02'],
    'ends 3' => ['3', '0.02'],
    'ends 4' => ['4', '0.01'],
    'ends 5' => ['5', '0.00'],
    'ends 6' => ['6', '-0.01'],
    'ends 7' => ['7', '-0.02'],
    'ends 8' => ['8', '0.02'],
    'ends 9' => ['9', '0.01'],
]);

it('rounds the amount itself', function () {
    $rounding = app(MalaysiaCashRounding::class);

    expect($rounding->round('10.02', PosRoundingMode::FiveSen))->toBe('10.00')
        ->and($rounding->round('10.03', PosRoundingMode::FiveSen))->toBe('10.05')
        ->and($rounding->round('10.08', PosRoundingMode::FiveSen))->toBe('10.10')
        ->and($rounding->round('0.01', PosRoundingMode::FiveSen))->toBe('0.00')
        ->and($rounding->round('10.04', PosRoundingMode::None))->toBe('10.04');
});

it('rounds to the nearest 10 sen with 5 going up', function (string $amount, string $adjustment) {
    expect(app(MalaysiaCashRounding::class)->adjustment(PosRoundingMode::TenSen, PosRoundingPolicy::CashLegOnly, $amount, $amount))->toBe($adjustment);
})->with([
    ['10.00', '0.00'], ['10.01', '-0.01'], ['10.04', '-0.04'], ['10.05', '0.05'], ['10.06', '0.04'], ['10.09', '0.01'],
]);

it('adds no rounding when it is off or no cash is paid', function () {
    $rounding = app(MalaysiaCashRounding::class);

    expect($rounding->adjustment(PosRoundingMode::None, PosRoundingPolicy::CashLegOnly, '10.03', '10.03'))->toBe('0.00')
        ->and($rounding->adjustment(PosRoundingMode::FiveSen, PosRoundingPolicy::CashLegOnly, '10.03', '0.00'))->toBe('0.00')
        ->and($rounding->adjustment(PosRoundingMode::FiveSen, PosRoundingPolicy::WholeBillWhenCashPresent, '10.03', '0.00'))->toBe('0.00');
});

it('rounds only the cash share under CashLegOnly and the whole bill under WholeBillWhenCashPresent', function () {
    $rounding = app(MalaysiaCashRounding::class);

    // RM 20.03 bill: RM 10.00 in cash and RM 10.03 on a card.
    expect($rounding->adjustment(PosRoundingMode::FiveSen, PosRoundingPolicy::CashLegOnly, '20.03', '10.00'))->toBe('0.00')
        ->and($rounding->adjustment(PosRoundingMode::FiveSen, PosRoundingPolicy::WholeBillWhenCashPresent, '20.03', '10.00'))->toBe('0.02');

    // The same bill paid fully in cash rounds the same either way.
    expect($rounding->adjustment(PosRoundingMode::FiveSen, PosRoundingPolicy::CashLegOnly, '20.03', '20.03'))->toBe('0.02')
        ->and($rounding->adjustment(PosRoundingMode::FiveSen, PosRoundingPolicy::WholeBillWhenCashPresent, '20.03', '20.03'))->toBe('0.02');
});
