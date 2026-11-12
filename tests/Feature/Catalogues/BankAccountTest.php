<?php

use App\Actions\Catalogues\ReorderBankAccounts;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\PropertyBankAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @param array<string, mixed> $overrides */
function bankAccountPayload(?PropertyBankAccount $bankAccount = null, array $overrides = []): array
{
    return array_merge([
        'bank_name' => $bankAccount->bank_name ?? 'Maybank',
        'account_name' => $bankAccount->account_name ?? 'Sand Bay Resort Sdn Bhd',
        'account_number' => $bankAccount->account_number ?? '5123 4567 8901',
        'swift_code' => $bankAccount?->swift_code,
        'active' => $bankAccount->active ?? true,
    ], $overrides);
}

test('the page lists available accounts first in order, only for this property', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $second = PropertyBankAccount::factory()->for($property)->create(['bank_name' => 'CIMB Bank', 'position' => 2]);
    $first = PropertyBankAccount::factory()->for($property)->create(['bank_name' => 'Maybank', 'position' => 1, 'swift_code' => 'MBBEMYKL']);
    $off = PropertyBankAccount::factory()->for($property)->inactive()->create(['bank_name' => 'RHB Bank', 'position' => 0]);
    PropertyBankAccount::factory()->create();

    $this->actingAs($owner)
        ->get(route('settings.bank-accounts.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/bank-accounts/index')
            ->where('bankAccounts', fn ($accounts) => collect($accounts)->pluck('id')->all() === [$first->id, $second->id, $off->id])
            ->where('bankAccounts.0.swiftCode', 'MBBEMYKL')
            ->where('bankAccounts.2.active', false)
            ->where('navigation.contexts.3.groups.3.items', fn ($items) => collect($items)->contains(
                fn (array $item): bool => $item['title'] === 'Bank accounts' && $item['active'] === true,
            )));
});

test('owners add an account at the end of the available list', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertyBankAccount::factory()->for($property)->create(['bank_name' => 'CIMB Bank', 'position' => 3]);

    $this->actingAs($owner)
        ->post(route('settings.bank-accounts.store', $property->code), bankAccountPayload(overrides: [
            'bank_name' => '  Maybank ',
            'account_number' => ' 5123 4567 8901 ',
            'swift_code' => 'mbbe mykl',
        ]))
        ->assertRedirect(route('settings.bank-accounts.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Maybank added.']);

    $account = $property->bankAccounts()->where('bank_name', 'Maybank')->sole();
    expect($account->account_number)->toBe('5123 4567 8901')
        ->and($account->swift_code)->toBe('MBBEMYKL')
        ->and($account->position)->toBe(4)
        ->and(Activity::query()->where('event', 'bank_account.created')->where('subject_id', $account->id)->exists())->toBeTrue();
});

test('an empty SWIFT code is saved as none', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('settings.bank-accounts.store', $owner->property->code), bankAccountPayload(overrides: ['swift_code' => ' ']))
        ->assertSessionHasNoErrors();

    expect($owner->property->bankAccounts()->sole()->swift_code)->toBeNull();
});

test('account fields are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('settings.bank-accounts.store', $owner->property->code), bankAccountPayload(overrides: $overrides))
        ->assertSessionHasErrors($field);

    expect($owner->property->bankAccounts()->count())->toBe(0);
})->with([
    'no bank' => [['bank_name' => ' '], 'bank_name'],
    'no account name' => [['account_name' => ''], 'account_name'],
    'no number' => [['account_number' => ''], 'account_number'],
    'number too long' => [['account_number' => str_repeat('1', 35)], 'account_number'],
    'SWIFT too short' => [['swift_code' => 'MBBEMY'], 'swift_code'],
    'SWIFT of 9' => [['swift_code' => 'MBBEMYKL1'], 'swift_code'],
]);

test('a number is unique within the property only', function () {
    $owner = User::factory()->owner()->create();
    $taken = PropertyBankAccount::factory()->for($owner->property)->create(['account_number' => '5123 4567 8901']);
    PropertyBankAccount::factory()->create(['account_number' => '8001 2233 44']);

    $this->actingAs($owner)
        ->post(route('settings.bank-accounts.store', $owner->property->code), bankAccountPayload(overrides: ['account_number' => '5123 4567 8901']))
        ->assertSessionHasErrors(['account_number' => 'Another bank account already uses this number.']);
    $this->post(route('settings.bank-accounts.store', $owner->property->code), bankAccountPayload(overrides: ['account_number' => '8001 2233 44']))
        ->assertSessionHasNoErrors();
    $this->put(route('settings.bank-accounts.update', [$owner->property->code, $taken]), bankAccountPayload($taken, ['bank_name' => 'Maybank Islamic']))
        ->assertSessionHasNoErrors();

    expect($taken->refresh()->bank_name)->toBe('Maybank Islamic');
});

test('turning an account off and on moves it to the end, and a save with no change logs nothing', function () {
    $owner = User::factory()->owner()->create();
    $account = PropertyBankAccount::factory()->for($owner->property)->create(['position' => 1]);
    PropertyBankAccount::factory()->for($owner->property)->create(['position' => 5]);
    $url = route('settings.bank-accounts.update', [$owner->property->code, $account]);

    $this->actingAs($owner)->put($url, bankAccountPayload($account, ['active' => false]));
    expect($account->refresh()->active)->toBeFalse();

    $this->put($url, bankAccountPayload($account, ['active' => true]));
    $this->put($url, bankAccountPayload($account->refresh()));

    expect($account->refresh()->active)->toBeTrue()
        ->and($account->position)->toBe(6)
        ->and(Activity::query()->where('event', 'bank_account.updated')->count())->toBe(2);
});

test('reordering saves the available order and refuses a stale or foreign list', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$first, $second, $third] = PropertyBankAccount::factory()->count(3)->for($property)
        ->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $off = PropertyBankAccount::factory()->for($property)->inactive()->create(['position' => 4]);
    $foreign = PropertyBankAccount::factory()->create();
    $url = route('settings.bank-accounts.reorder', $property->code);

    $this->actingAs($owner)->put($url, ['order' => [$third->id, $first->id, $second->id]]);

    expect($property->bankAccounts()->active()->ordered()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id])
        ->and($off->refresh()->position)->toBe(4);

    $this->put($url, ['order' => [$first->id, $second->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The bank accounts changed. Reload the page and try again.']);

    expect(app(ReorderBankAccounts::class)($property, $owner, [$first->id, $second->id, $foreign->id]))->toBeFalse()
        ->and(Activity::query()->where('event', 'bank_account.reordered')->count())->toBe(1);
});

test('routes need settings permission and stay inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $foreign = PropertyBankAccount::factory()->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.bank-accounts.index', $frontDesk->property->code))
        ->assertForbidden();
    $this->post(route('settings.bank-accounts.store', $frontDesk->property->code), bankAccountPayload())
        ->assertForbidden();
    $this->actingAs($owner)
        ->put(route('settings.bank-accounts.update', [$owner->property->code, $foreign]), bankAccountPayload($foreign))
        ->assertNotFound();
});

test('the database refuses blank text and a bad SWIFT code', function (array $attributes) {
    $property = Property::factory()->create();

    DB::table('property_bank_accounts')->insert(array_merge([
        'property_id' => $property->id,
        'bank_name' => 'Maybank',
        'account_name' => 'Sand Bay Resort Sdn Bhd',
        'account_number' => '5123 4567 8901',
        'swift_code' => null,
        'position' => 1,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
})->with([
    'blank bank' => [['bank_name' => ' ']],
    'untrimmed number' => [['account_number' => ' 5123']],
    'lower case SWIFT' => [['swift_code' => 'mbbemykl']],
    'SWIFT of 9' => [['swift_code' => 'MBBEMYKL1']],
])->throws(QueryException::class);
