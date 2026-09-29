<?php

use App\Actions\Catalogues\ReorderPaymentMethods;
use App\Actions\Catalogues\SeedPaymentMethods;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @param array<string, mixed> $overrides */
function paymentMethodPayload(PaymentMethod $paymentMethod, array $overrides = []): array
{
    return array_merge([
        'name' => $paymentMethod->name,
        'code' => $paymentMethod->transactionCode->code,
        'surcharge_type' => $paymentMethod->surcharge_type->value,
        'surcharge_value' => $paymentMethod->surcharge_value,
        'surcharge_charge_item_id' => $paymentMethod->surcharge_charge_item_id,
        'requires_reference' => $paymentMethod->requires_reference,
        'active' => $paymentMethod->active,
    ], $overrides);
}

test('payment method and surcharge types expose their labels and options', function () {
    expect(PaymentMethodType::DirectBill->label())->toBe('City ledger')
        ->and(PaymentMethodType::OtaCollected->label())->toBe('OTA collected')
        ->and(PaymentMethodType::options())->toBe([
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'card', 'label' => 'Card'],
            ['value' => 'bank_transfer', 'label' => 'Bank transfer'],
            ['value' => 'e_wallet', 'label' => 'E-wallet'],
            ['value' => 'ota_collected', 'label' => 'OTA collected'],
            ['value' => 'direct_bill', 'label' => 'City ledger'],
        ])
        ->and(SurchargeType::options())->toBe([
            ['value' => 'none', 'label' => 'None'],
            ['value' => 'fixed', 'label' => 'Fixed amount'],
            ['value' => 'percent', 'label' => 'Percentage'],
        ]);
});

test('Malaysia properties receive six payment methods idempotently', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    app(SeedTransactionCodes::class)($property, 'MY');

    app(SeedPaymentMethods::class)($property, 'MY');
    $card = $property->paymentMethods()->where('type', PaymentMethodType::Card)->sole();
    $card->update([
        'name' => 'Front desk terminal',
        'requires_reference' => false,
        'active' => false,
        'position' => 9,
    ]);
    app(SeedPaymentMethods::class)($property, 'MY');

    $methods = $property->paymentMethods()->ordered()->get();
    expect($methods)->toHaveCount(6)
        ->and($methods->where('active', true)->pluck('type')->all())->toBe([
            PaymentMethodType::Cash,
            PaymentMethodType::BankTransfer,
            PaymentMethodType::EWallet,
        ])
        ->and($methods->where('requires_reference', true)->pluck('type')->all())->toBe([
            PaymentMethodType::BankTransfer,
            PaymentMethodType::EWallet,
        ])
        ->and($card->refresh()->name)->toBe('Front desk terminal')
        ->and($card->active)->toBeFalse()
        ->and($card->position)->toBe(9)
        ->and($methods->firstWhere('type', PaymentMethodType::OtaCollected)->active)->toBeFalse()
        ->and($methods->firstWhere('type', PaymentMethodType::DirectBill)->active)->toBeFalse();
});

test('unsupported countries receive no payment method presets', function () {
    $property = Property::factory()->create();

    expect(fn () => app(SeedPaymentMethods::class)($property, 'SG'))
        ->toThrow(InvalidArgumentException::class);

    expect($property->paymentMethods()->count())->toBe(0);
});

test('the page loads the bounded catalogue and surcharge picker data', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    app(SeedTransactionCodes::class)($property, 'MY');
    app(SeedPaymentMethods::class)($property, 'MY');
    $activeChargeItem = ChargeItem::factory()->for($property)->create(['name' => 'Card fee']);
    $inactiveChargeItem = ChargeItem::factory()->for($property)->inactive()->create(['name' => 'Old fee']);
    $card = $property->paymentMethods()->where('type', PaymentMethodType::Card)->sole();
    $card->update([
        'surcharge_type' => SurchargeType::Fixed,
        'surcharge_value' => '2.00',
        'surcharge_charge_item_id' => $inactiveChargeItem->id,
    ]);
    ChargeItem::factory()->create(['name' => 'Another property fee']);

    $this->actingAs($owner)
        ->get(route('settings.payment-methods.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/payment-methods/index')
            ->has('paymentMethods', 6)
            ->where('paymentMethods.0.name', 'Cash')
            ->where('paymentMethods.1.typeLabel', 'Card')
            ->where('paymentMethods.1.surchargeChargeItem', 'Old fee')
            ->where('chargeItems', fn ($items) => collect($items)->pluck('id')->all() === [$activeChargeItem->id, $inactiveChargeItem->id])
            ->where('paymentMethods.1.code', 'CARD')
            ->where('paymentMethods.1.isSystem', true)
            ->where('types', PaymentMethodType::addableOptions())
            ->where('surchargeTypes', SurchargeType::options())
            ->where('currency', Currency::Myr->value)
            ->where('navigation.contexts.1.groups.2.items.2.title', 'Payment methods')
            ->where('navigation.contexts.1.groups.2.items.2.active', true));
});

test('owners update payment settings and synchronize the transaction code name', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    app(SeedTransactionCodes::class)($property, 'MY');
    app(SeedPaymentMethods::class)($property, 'MY');
    $card = $property->paymentMethods()->where('type', PaymentMethodType::Card)->sole();
    $chargeItem = ChargeItem::factory()->for($property)->create();

    $this->actingAs($owner)
        ->put(route('settings.payment-methods.update', [$property->code, $card]), paymentMethodPayload($card, [
            'name' => ' Front desk terminal ',
            'surcharge_type' => SurchargeType::Percent->value,
            'surcharge_value' => '2.5',
            'surcharge_charge_item_id' => $chargeItem->id,
            'requires_reference' => true,
            'code' => 'TERMINAL',
        ]))
        ->assertRedirect(route('settings.payment-methods.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Front desk terminal saved.']);

    expect($card->refresh()->name)->toBe('Front desk terminal')
        ->and($card->type)->toBe(PaymentMethodType::Card)
        ->and($card->surcharge_type)->toBe(SurchargeType::Percent)
        ->and($card->surcharge_value)->toBe('2.50')
        ->and($card->surcharge_charge_item_id)->toBe($chargeItem->id)
        ->and($card->transactionCode->name)->toBe('Front desk terminal')
        ->and($card->transactionCode->code)->toBe('CARD')
        ->and(Activity::query()->where('event', 'payment_method.updated')->count())->toBe(1);
});

test('owners add payment methods at the end with their own payment code', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $chargeItem = ChargeItem::factory()->for($property)->create();
    PaymentMethod::factory()->for($property)->create(['position' => 3]);

    $this->actingAs($owner)
        ->post(route('settings.payment-methods.store', $property->code), [
            'name' => ' DuitNow QR ',
            'type' => PaymentMethodType::EWallet->value,
            'code' => ' duitnow ',
            'surcharge_type' => SurchargeType::Percent->value,
            'surcharge_value' => '1.5',
            'surcharge_charge_item_id' => $chargeItem->id,
            'requires_reference' => true,
            'active' => true,
        ])
        ->assertRedirect(route('settings.payment-methods.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'DuitNow QR created.']);

    $paymentMethod = PaymentMethod::query()->where('name', 'DuitNow QR')->sole();
    $transactionCode = $paymentMethod->transactionCode;

    expect($paymentMethod->type)->toBe(PaymentMethodType::EWallet)
        ->and($paymentMethod->position)->toBe(4)
        ->and($paymentMethod->surcharge_value)->toBe('1.50')
        ->and($transactionCode->property_id)->toBe($property->id)
        ->and($transactionCode->code)->toBe('DUITNOW')
        ->and($transactionCode->name)->toBe('DuitNow QR')
        ->and($transactionCode->kind)->toBe(TransactionKind::Payment)
        ->and($transactionCode->category)->toBe(TransactionCategory::EWallet)
        ->and($transactionCode->system_key)->toBeNull()
        ->and(Activity::query()->where('event', 'payment_method.created')->count())->toBe(1);
});

test('new payment methods need an addable type and an unused code', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    TransactionCode::factory()->for($owner->property)->create(['code' => 'CASH']);

    $this->actingAs($owner)
        ->post(route('settings.payment-methods.store', $owner->property->code), array_merge([
            'name' => 'DuitNow QR',
            'type' => PaymentMethodType::EWallet->value,
            'code' => 'DUITNOW',
            'surcharge_type' => SurchargeType::None->value,
            'requires_reference' => true,
            'active' => true,
        ], $overrides))
        ->assertSessionHasErrors($field);

    expect(PaymentMethod::query()->count())->toBe(0);
})->with([
    'type is required' => [['type' => ''], 'type'],
    'OTA collected stays system-only' => [['type' => PaymentMethodType::OtaCollected->value], 'type'],
    'City ledger stays system-only' => [['type' => PaymentMethodType::DirectBill->value], 'type'],
    'code is required' => [['code' => ''], 'code'],
    'code is unique among transaction codes' => [['code' => 'cash'], 'code'],
    'code is at most 20 characters' => [['code' => str_repeat('A', 21)], 'code'],
]);

test('custom methods update their code and status while the type stays fixed', function () {
    $owner = User::factory()->owner()->create();
    $paymentMethod = PaymentMethod::factory()->for($owner->property)->create(['type' => PaymentMethodType::EWallet]);

    $this->actingAs($owner)
        ->put(route('settings.payment-methods.update', [$owner->property->code, $paymentMethod]), paymentMethodPayload($paymentMethod, [
            'code' => 'TNG',
            'type' => PaymentMethodType::Cash->value,
            'active' => false,
        ]))
        ->assertSessionHasNoErrors();

    expect($paymentMethod->refresh()->type)->toBe(PaymentMethodType::EWallet)
        ->and($paymentMethod->transactionCode->code)->toBe('TNG')
        ->and($paymentMethod->transactionCode->active)->toBeFalse();
});

test('selecting no surcharge clears its value and charge item', function () {
    $owner = User::factory()->owner()->create();
    $chargeItem = ChargeItem::factory()->for($owner->property)->create();
    $paymentMethod = PaymentMethod::factory()->for($owner->property)->create([
        'surcharge_type' => SurchargeType::Fixed,
        'surcharge_value' => '3.00',
        'surcharge_charge_item_id' => $chargeItem->id,
    ]);

    $this->actingAs($owner)->put(
        route('settings.payment-methods.update', [$owner->property->code, $paymentMethod]),
        paymentMethodPayload($paymentMethod, [
            'surcharge_type' => SurchargeType::None->value,
            'surcharge_value' => '99.00',
            'surcharge_charge_item_id' => $chargeItem->id,
        ]),
    )->assertSessionHasNoErrors();

    expect($paymentMethod->refresh()->surcharge_type)->toBe(SurchargeType::None)
        ->and($paymentMethod->surcharge_value)->toBeNull()
        ->and($paymentMethod->surcharge_charge_item_id)->toBeNull();
});

test('payment method fields and surcharge completeness are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $paymentMethod = PaymentMethod::factory()->for($owner->property)->create(['name' => 'Card']);
    PaymentMethod::factory()->for($owner->property)->create(['name' => 'Cash']);

    $this->actingAs($owner)
        ->put(
            route('settings.payment-methods.update', [$owner->property->code, $paymentMethod]),
            paymentMethodPayload($paymentMethod, $overrides),
        )
        ->assertSessionHasErrors($field);
})->with([
    'name is required' => [['name' => ''], 'name'],
    'name is unique ignoring case' => [['name' => 'cAsH'], 'name'],
    'surcharge type is known' => [['surcharge_type' => 'ratio'], 'surcharge_type'],
    'configured surcharge needs a value' => [[
        'surcharge_type' => 'fixed', 'surcharge_value' => null, 'surcharge_charge_item_id' => 1,
    ], 'surcharge_value'],
    'configured surcharge needs a charge item' => [[
        'surcharge_type' => 'fixed', 'surcharge_value' => '2.00', 'surcharge_charge_item_id' => null,
    ], 'surcharge_charge_item_id'],
    'percentage is at most one hundred' => [[
        'surcharge_type' => 'percent', 'surcharge_value' => '100.01', 'surcharge_charge_item_id' => 1,
    ], 'surcharge_value'],
]);

test('surcharge charge items must be active and belong to the property', function (string $case) {
    $owner = User::factory()->owner()->create();
    $paymentMethod = PaymentMethod::factory()->for($owner->property)->create();
    $chargeItem = match ($case) {
        'inactive' => ChargeItem::factory()->for($owner->property)->inactive()->create(),
        'another property' => ChargeItem::factory()->create(),
    };

    $this->actingAs($owner)
        ->put(
            route('settings.payment-methods.update', [$owner->property->code, $paymentMethod]),
            paymentMethodPayload($paymentMethod, [
                'surcharge_type' => SurchargeType::Fixed->value,
                'surcharge_value' => '2.00',
                'surcharge_charge_item_id' => $chargeItem->id,
            ]),
        )
        ->assertSessionHasErrors('surcharge_charge_item_id');
})->with(['inactive', 'another property']);

test('an existing surcharge may retain an inactive charge item', function () {
    $owner = User::factory()->owner()->create();
    $chargeItem = ChargeItem::factory()->for($owner->property)->create();
    $paymentMethod = PaymentMethod::factory()->for($owner->property)->create([
        'surcharge_type' => SurchargeType::Fixed,
        'surcharge_value' => '2.00',
        'surcharge_charge_item_id' => $chargeItem->id,
    ]);
    $chargeItem->update(['active' => false]);

    $this->actingAs($owner)
        ->put(
            route('settings.payment-methods.update', [$owner->property->code, $paymentMethod]),
            paymentMethodPayload($paymentMethod, ['name' => 'Card terminal']),
        )
        ->assertSessionHasNoErrors();

    expect($paymentMethod->refresh()->surcharge_charge_item_id)->toBe($chargeItem->id);
});

test('owners deactivate reactivate and save without logging no-op updates', function () {
    $owner = User::factory()->owner()->create();
    PaymentMethod::factory()->for($owner->property)->create(['position' => 4]);
    $paymentMethod = PaymentMethod::factory()->for($owner->property)->create(['position' => 2]);
    $url = route('settings.payment-methods.update', [$owner->property->code, $paymentMethod]);

    $this->actingAs($owner)->put($url, paymentMethodPayload($paymentMethod, ['active' => false]));
    $this->put($url, paymentMethodPayload($paymentMethod->refresh(), ['active' => true]));
    $this->put($url, paymentMethodPayload($paymentMethod->refresh()));

    expect($paymentMethod->refresh()->active)->toBeTrue()
        ->and($paymentMethod->position)->toBe(5)
        ->and(Activity::query()->where('event', 'payment_method.updated')->count())->toBe(2);
});

test('reordering saves active positions and rejects stale duplicate and foreign lists', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$first, $second, $third] = PaymentMethod::factory()->count(3)->for($property)
        ->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $inactive = PaymentMethod::factory()->for($property)->inactive()->create(['position' => 4]);
    $foreign = PaymentMethod::factory()->create();
    $url = route('settings.payment-methods.reorder', $property->code);

    $this->actingAs($owner)->put($url, ['order' => [$third->id, $first->id, $second->id]]);

    expect($property->paymentMethods()->active()->ordered()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id])
        ->and($inactive->refresh()->position)->toBe(4);

    $this->put($url, ['order' => [$first->id, $second->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The payment methods changed. Reload the page and try again.']);
    $this->put($url, ['order' => [$first->id, $first->id, $second->id]])
        ->assertSessionHasErrors('order.1');

    expect(app(ReorderPaymentMethods::class)($property, $owner, [$first->id, $second->id, $foreign->id]))->toBeFalse()
        ->and(Activity::query()->where('event', 'payment_method.reordered')->count())->toBe(1);
});

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherPaymentMethod = PaymentMethod::factory()->create();
    $ownerPaymentMethod = PaymentMethod::factory()->for($owner->property)->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.payment-methods.index', $frontDesk->property->code))
        ->assertForbidden();
    $this->post(route('settings.payment-methods.store', $frontDesk->property->code), paymentMethodPayload($ownerPaymentMethod, ['type' => 'cash']))
        ->assertForbidden();
    $this->actingAs($owner)
        ->put(
            route('settings.payment-methods.update', [$owner->property->code, $otherPaymentMethod]),
            paymentMethodPayload($ownerPaymentMethod),
        )
        ->assertNotFound();
});

test('the database enforces payment method invariants', function (array $attributes) {
    $property = Property::factory()->create();
    $transactionCode = TransactionCode::factory()->for($property)->create();
    $chargeItem = ChargeItem::factory()->for($property)->create();

    if (($attributes['surcharge_charge_item_id'] ?? null) === 'charge-item') {
        $attributes['surcharge_charge_item_id'] = $chargeItem->id;
    }

    DB::table('payment_methods')->insert([
        'property_id' => $property->id,
        'transaction_code_id' => $transactionCode->id,
        'name' => 'Card',
        'type' => PaymentMethodType::Card->value,
        'surcharge_type' => SurchargeType::None->value,
        'surcharge_value' => null,
        'surcharge_charge_item_id' => null,
        'requires_reference' => true,
        'position' => 1,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
})->throws(QueryException::class)->with([
    'known method type' => [['type' => 'cheque']],
    'known surcharge type' => [['surcharge_type' => 'ratio']],
    'complete fixed surcharge' => [['surcharge_type' => 'fixed', 'surcharge_value' => '2.00']],
    'positive surcharge' => [[
        'surcharge_type' => 'fixed', 'surcharge_value' => '0.00', 'surcharge_charge_item_id' => 'charge-item',
    ]],
    'percentage maximum' => [[
        'surcharge_type' => 'percent', 'surcharge_value' => '100.01', 'surcharge_charge_item_id' => 'charge-item',
    ]],
    'trimmed name' => [['name' => ' Card ']],
]);
