<?php

require_once __DIR__.'/../Support/CommissionSetup.php';

use App\Actions\Commissions\RecordDueCommissions;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\CommissionEntry;

test('a checked-out Channex stay records 3% of room revenue before tax and no money', function () {
    $reservation = channelCommissionReservation();
    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '300.00');
    commissionFolioLine($reservation, SystemTransactionCode::SstTax, '24.00', TransactionKind::Tax);
    endCommissionStay($reservation);

    expect(app(RecordDueCommissions::class)())->toBe(1);

    $entry = CommissionEntry::query()->sole();
    expect($entry->type)->toBe(CommissionEntryType::Commission)
        ->and($entry->service)->toBe(CommissionService::ChannelManager)
        ->and($entry->base_amount)->toBe('300.00')
        ->and($entry->rate)->toBe('3.00')
        ->and($entry->amount)->toBe('-9.00')
        ->and($entry->due_date->toDateString())->toBe('2026-10-14');
});

test('an OTA booking that did not come through Channex records nothing', function () {
    $reservation = channelCommissionReservation(fromChannex: false);
    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '300.00');
    endCommissionStay($reservation);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->count())->toBe(0);
});

test('the charging period decides whether a stay pays', function (?string $startsOn, int $entries) {
    $reservation = channelCommissionReservation(chargedFrom: $startsOn);
    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '300.00');
    endCommissionStay($reservation);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->count())->toBe($entries);
})->with([
    'no period' => [null, 0],
    'starts the day after check-out' => ['2026-10-15', 0],
    'starts on the check-out date' => ['2026-10-14', 1],
]);

test('a cancellation from the OTA with no fee records nothing', function () {
    $reservation = channelCommissionReservation();
    endCommissionStay($reservation, UnitStatus::Cancelled);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->count())->toBe(0);
});

test('a cancellation or no-show fee posted by staff pays 3% of the fee before tax', function (SystemTransactionCode $code, UnitStatus $status, string $fee, string $commission) {
    $reservation = channelCommissionReservation();
    commissionFolioLine($reservation, $code, $fee);
    commissionFolioLine($reservation, SystemTransactionCode::SstTax, '16.00', TransactionKind::Tax);
    endCommissionStay($reservation, $status);

    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->sole()->amount)->toBe($commission);
})->with([
    'cancellation' => [SystemTransactionCode::CancellationRevenue, UnitStatus::Cancelled, '200.00', '-6.00'],
    'no-show' => [SystemTransactionCode::NoShowRevenue, UnitStatus::NoShow, '180.00', '-5.40'],
]);

test('a fee reversed later reverses the commission', function () {
    $reservation = channelCommissionReservation();
    commissionFolioLine($reservation, SystemTransactionCode::CancellationRevenue, '200.00');
    endCommissionStay($reservation, UnitStatus::Cancelled);
    app(RecordDueCommissions::class)();

    commissionFolioLine($reservation, SystemTransactionCode::CancellationRevenue, '-200.00');
    app(RecordDueCommissions::class)();

    expect(CommissionEntry::query()->orderBy('id')->pluck('amount')->all())->toBe(['-6.00', '6.00']);
});

test('a recorded booking is checked again only after a recent change', function () {
    $reservation = channelCommissionReservation();
    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '300.00');
    endCommissionStay($reservation);
    app(RecordDueCommissions::class)();

    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '100.00');
    $this->travel(4)->hours();

    expect(app(RecordDueCommissions::class)())->toBe(0)
        ->and(CommissionEntry::query()->count())->toBe(1);

    commissionFolioLine($reservation, SystemTransactionCode::RoomRevenue, '100.00');

    expect(app(RecordDueCommissions::class)())->toBe(1)
        ->and(CommissionEntry::query()->orderBy('id')->pluck('amount')->all())->toBe(['-9.00', '9.00', '-15.00']);
});
