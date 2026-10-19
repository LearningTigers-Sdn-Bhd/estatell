<?php

use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Services\Property\CountryProfiles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

dataset('system transaction codes', [
    'room revenue' => [SystemTransactionCode::RoomRevenue, 'ROOM', 'Room revenue', TransactionKind::Charge, TransactionCategory::Room],
    'no-show revenue' => [SystemTransactionCode::NoShowRevenue, 'NO_SHOW', 'No-show revenue', TransactionKind::Charge, TransactionCategory::Room],
    'cancellation revenue' => [SystemTransactionCode::CancellationRevenue, 'CANCEL', 'Cancellation revenue', TransactionKind::Charge, TransactionCategory::Room],
    'late checkout revenue' => [SystemTransactionCode::LateCheckoutRevenue, 'LATE_CHECKOUT', 'Late checkout revenue', TransactionKind::Charge, TransactionCategory::Room],
    'early departure revenue' => [SystemTransactionCode::EarlyDepartureRevenue, 'EARLY_DEPARTURE', 'Early departure revenue', TransactionKind::Charge, TransactionCategory::Room],
    'food and beverage revenue' => [SystemTransactionCode::FoodAndBeverageRevenue, 'FNB', 'Food and beverage', TransactionKind::Charge, TransactionCategory::FoodAndBeverage],
    'laundry revenue' => [SystemTransactionCode::LaundryRevenue, 'LAUNDRY', 'Laundry', TransactionKind::Charge, TransactionCategory::Laundry],
    'minibar revenue' => [SystemTransactionCode::MinibarRevenue, 'MINIBAR', 'Minibar', TransactionKind::Charge, TransactionCategory::Minibar],
    'fee revenue' => [SystemTransactionCode::FeeRevenue, 'FEE', 'General fee', TransactionKind::Charge, TransactionCategory::Fee],
    'SST' => [SystemTransactionCode::SstTax, 'SST', 'SST', TransactionKind::Tax, TransactionCategory::Tax],
    'tourism tax' => [SystemTransactionCode::TourismTax, 'TOURISM_TAX', 'Tourism tax', TransactionKind::Tax, TransactionCategory::Tax],
    'cash payment' => [SystemTransactionCode::CashPayment, 'CASH', 'Cash', TransactionKind::Payment, TransactionCategory::Cash],
    'card payment' => [SystemTransactionCode::CardPayment, 'CARD', 'Card', TransactionKind::Payment, TransactionCategory::Card],
    'bank transfer payment' => [SystemTransactionCode::BankTransferPayment, 'BANK_TRANSFER', 'Bank transfer', TransactionKind::Payment, TransactionCategory::BankTransfer],
    'e-wallet payment' => [SystemTransactionCode::EWalletPayment, 'E_WALLET', 'E-wallet', TransactionKind::Payment, TransactionCategory::EWallet],
    'OTA collected payment' => [SystemTransactionCode::OtaCollectedPayment, 'OTA_COLLECTED', 'OTA collected', TransactionKind::Payment, TransactionCategory::OtaCollected],
    'direct bill payment' => [SystemTransactionCode::DirectBillPayment, 'DIRECT_BILL', 'City ledger', TransactionKind::Payment, TransactionCategory::DirectBill],
    'security deposit' => [SystemTransactionCode::SecurityDeposit, 'SECURITY_DEPOSIT', 'Security deposit', TransactionKind::Payment, TransactionCategory::Deposit],
    'refund' => [SystemTransactionCode::Refund, 'REFUND', 'Refund', TransactionKind::Payment, TransactionCategory::Refund],
    'adjustment' => [SystemTransactionCode::Adjustment, 'ADJUSTMENT', 'Adjustment', TransactionKind::Adjustment, TransactionCategory::Adjustment],
    'discount' => [SystemTransactionCode::Discount, 'DISCOUNT', 'Discount', TransactionKind::Adjustment, TransactionCategory::Discount],
]);

test('transaction enums expose their labels and options', function () {
    expect(TransactionKind::options())->toBe([
        ['value' => 'charge', 'label' => 'Charge'],
        ['value' => 'payment', 'label' => 'Payment'],
        ['value' => 'adjustment', 'label' => 'Adjustment'],
        ['value' => 'tax', 'label' => 'Tax'],
    ])->and(TransactionCategory::options())->toBe([
        ['value' => 'room', 'label' => 'Room'],
        ['value' => 'food_and_beverage', 'label' => 'Food and beverage'],
        ['value' => 'laundry', 'label' => 'Laundry'],
        ['value' => 'minibar', 'label' => 'Minibar'],
        ['value' => 'transport', 'label' => 'Transport'],
        ['value' => 'fee', 'label' => 'Fee'],
        ['value' => 'tax', 'label' => 'Tax'],
        ['value' => 'discount', 'label' => 'Discount'],
        ['value' => 'cash', 'label' => 'Cash'],
        ['value' => 'card', 'label' => 'Card'],
        ['value' => 'bank_transfer', 'label' => 'Bank transfer'],
        ['value' => 'e_wallet', 'label' => 'E-wallet'],
        ['value' => 'ota_collected', 'label' => 'OTA collected'],
        ['value' => 'direct_bill', 'label' => 'City ledger'],
        ['value' => 'deposit', 'label' => 'Deposit'],
        ['value' => 'refund', 'label' => 'Refund'],
        ['value' => 'adjustment', 'label' => 'Adjustment'],
        ['value' => 'opening_balance', 'label' => 'Opening balance'],
        ['value' => 'old_system', 'label' => 'Old system'],
        ['value' => 'other', 'label' => 'Other'],
    ]);
});

test('each system transaction code defines its accounting identity', function (
    SystemTransactionCode $systemCode,
    string $code,
    string $label,
    TransactionKind $kind,
    TransactionCategory $category,
) {
    expect($systemCode->code())->toBe($code)
        ->and($systemCode->label())->toBe($label)
        ->and($systemCode->kind())->toBe($kind)
        ->and($systemCode->category())->toBe($category);
})->with('system transaction codes');

test('transaction codes cast their classifications and expose property and active queries', function () {
    $property = Property::factory()->create();
    $active = TransactionCode::factory()->for($property)->system()->create();
    TransactionCode::factory()->for($property)->inactive()->create();

    expect($active->kind)->toBe(TransactionKind::Charge)
        ->and($active->category)->toBe(TransactionCategory::Room)
        ->and($active->is_system)->toBeTrue()
        ->and($active->active)->toBeTrue()
        ->and($active->property->is($property))->toBeTrue()
        ->and($property->transactionCodes()->count())->toBe(2)
        ->and(TransactionCode::query()->active()->sole()->is($active))->toBeTrue();
});

test('codes and system keys are unique within a property', function (string $column) {
    $property = Property::factory()->create();
    $attributes = $column === 'code'
        ? ['code' => 'ROOM']
        : ['system_key' => 'room_revenue', 'code' => 'ROOM', 'is_system' => true];
    TransactionCode::factory()->for($property)->create($attributes);

    expect(fn () => TransactionCode::factory()->for($property)->create([
        ...$attributes,
        'code' => $column === 'code' ? 'ROOM' : 'ROOM_2',
    ]))->toThrow(QueryException::class);
})->with(['code', 'system_key']);

test('different properties may reuse codes and system keys', function () {
    TransactionCode::factory()->for(Property::factory())->system()->create();
    $other = TransactionCode::factory()->for(Property::factory())->system()->create();

    expect($other->exists)->toBeTrue();
});

test('the database rejects invalid transaction code values', function (array $attributes) {
    $property = Property::factory()->create();

    expect(fn () => DB::table('transaction_codes')->insert([
        'property_id' => $property->id,
        'system_key' => null,
        'code' => 'CUSTOM',
        'name' => 'Custom code',
        'kind' => 'charge',
        'category' => 'other',
        'gl_account_code' => null,
        'is_system' => false,
        'active' => true,
        ...$attributes,
    ]))->toThrow(QueryException::class);
})->with([
    'invalid kind' => [['kind' => 'credit']],
    'invalid category' => [['category' => 'accommodation']],
    'blank code' => [['code' => '']],
    'untrimmed code' => [['code' => ' ROOM']],
    'blank name' => [['name' => ' ']],
    'blank system key' => [['system_key' => '', 'is_system' => true]],
    'blank GL account' => [['gl_account_code' => '']],
    'system record without key' => [['system_key' => null, 'is_system' => true]],
    'custom record with key' => [['system_key' => 'custom', 'is_system' => false]],
]);

test('Malaysia properties receive every system transaction code', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);

    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));

    expect($property->transactionCodes()->count())->toBe(count(SystemTransactionCode::cases()))
        ->and($property->transactionCodes()->where('is_system', true)->count())->toBe(count(SystemTransactionCode::cases()))
        ->and($property->transactionCodes()->where('active', true)->count())->toBe(count(SystemTransactionCode::cases()))
        ->and($property->transactionCodes()->whereNotNull('gl_account_code')->count())->toBe(0)
        ->and($property->transactionCodes()->orderBy('system_key')->pluck('system_key')->all())
        ->toBe(collect(SystemTransactionCode::cases())->pluck('value')->sort()->values()->all());
});

test('seeding again preserves existing system transaction codes', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    $room = TransactionCode::factory()->for($property)->system()->create([
        'code' => 'ROOMS',
        'name' => 'Accommodation',
        'kind' => TransactionKind::Adjustment,
        'category' => TransactionCategory::Other,
        'gl_account_code' => '4100',
        'active' => false,
    ]);

    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));

    expect($property->transactionCodes()->count())->toBe(count(SystemTransactionCode::cases()))
        ->and($room->refresh()->only(['code', 'name', 'gl_account_code', 'active']))->toBe([
            'code' => 'ROOMS',
            'name' => 'Accommodation',
            'gl_account_code' => '4100',
            'active' => false,
        ])->and($room->kind)->toBe(TransactionKind::Adjustment)
        ->and($room->category)->toBe(TransactionCategory::Other);
});

test('system codes use the next suffix when custom codes occupy their canonical code', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    TransactionCode::factory()->for($property)->create(['code' => 'ROOM']);
    TransactionCode::factory()->for($property)->create(['code' => 'ROOM_2']);

    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));

    expect($property->transactionCodes()->where('system_key', SystemTransactionCode::RoomRevenue->value)->value('code'))
        ->toBe('ROOM_3')
        ->and($property->transactionCodes()->whereNull('system_key')->whereIn('code', ['ROOM', 'ROOM_2'])->count())->toBe(2);
});
