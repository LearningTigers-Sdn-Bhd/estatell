<?php

use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\AccountingProvider;
use App\Enums\AccountingSyncStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Features\AccountingIntegration;
use App\Models\AccountingConnection;
use App\Models\AccountingDocument;
use App\Models\AccountingSync;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Property\CountryProfiles;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * An owner of a live property that has the Accounting integration feature on and the Malaysian transaction codes.
 *
 * @return array{0: User, 1: Property}
 */
function accountingOwner(bool $feature = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    app(SeedTransactionCodes::class)($property, CountryProfiles::for('MY'));

    if ($feature) {
        Feature::for($property)->activate(AccountingIntegration::class);
    }

    return [$owner, $property];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function connectionPayload(array $overrides = []): array
{
    return [
        'provider' => 'qne',
        'api_key' => '11111111-2222-3333-4444-555555555555',
        'guest_ledger_account_code' => '300-000',
        'enabled' => true,
        ...$overrides,
    ];
}

it('answers 404 while the Accounting integration feature is off', function () {
    [$owner, $property] = accountingOwner(feature: false);

    $this->actingAs($owner)->get(route('settings.accounting.edit', $property->code))->assertNotFound();
});

it('shows the three tabs, each with its own data', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)->get(route('settings.accounting.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/accounting/index')
            ->where('connection', null)
            ->has('providers', 2)
            ->where('providers.0.value', 'qne')
            ->where('setup.status.value', 'not-connected')
            ->missing('mappings')
            ->missing('syncs'));

    $this->actingAs($owner)->get(route('settings.accounting.accounts', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/accounting/accounts')
            ->has('mappings')
            ->where('mappings.0.kindLabel', 'Charge')
            ->missing('connection'));

    $this->actingAs($owner)->get(route('settings.accounting.history', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/accounting/history')
            ->has('syncs', 0)
            ->where('setup.lastSync', null));
});

it('lets a property map accounts before it has a connection', function () {
    [$owner, $property] = accountingOwner();
    $room = TransactionCode::query()->where('property_id', $property->id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->firstOrFail();

    $this->actingAs($owner)
        ->put(route('settings.accounting.mappings', $property->code), ['accounts' => [$room->id => '500-000']])
        ->assertRedirect(route('settings.accounting.accounts', $property->code));

    $this->actingAs($owner)->get(route('settings.accounting.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('setup.status.value', 'not-connected')
            ->where('setup.accountsMapped', 1)
            ->where('setup.connectionComplete', false));
});

it('leaves the opening balance code out of the accounts', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)->get(route('settings.accounting.accounts', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mappings', fn ($mappings) => collect($mappings)->doesntContain('code', 'OPENING_BAL')
                && collect($mappings)->contains('code', 'ROOM')));
});

it('works out the setup status from the connection', function () {
    [$owner, $property] = accountingOwner();
    $connection = AccountingConnection::factory()->for($property)->create(['enabled' => false, 'last_error' => null]);

    $status = fn (): string => $this->actingAs($owner)->get(route('settings.accounting.edit', $property->code))
        ->viewData('page')['props']['setup']['status']['value'];

    expect($status())->toBe('ready');

    $connection->forceFill(['enabled' => true])->save();
    expect($status())->toBe('live');

    $connection->forceFill(['last_error' => 'QNE could not be reached.'])->save();
    expect($status())->toBe('needs-attention');

    $connection->forceFill(['api_key' => null])->save();
    expect($status())->toBe('not-connected');
});

it('needs a complete connection before the test button is offered', function () {
    [$owner, $property] = accountingOwner();
    $connection = AccountingConnection::factory()->for($property)->create(['guest_ledger_account_code' => null]);

    $complete = fn (): bool => $this->actingAs($owner)->get(route('settings.accounting.edit', $property->code))
        ->viewData('page')['props']['setup']['connectionComplete'];

    expect($complete())->toBeFalse();

    $connection->forceFill(['guest_ledger_account_code' => '300-000'])->save();
    expect($complete())->toBeTrue();
});

it('keeps the API key out of the page and encrypts it at rest', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload())
        ->assertRedirect(route('settings.accounting.edit', $property->code));

    $connection = $property->refresh()->accountingConnection;

    expect($connection->api_key)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($connection->getRawOriginal('api_key'))->not->toContain('11111111-2222')
        ->and(Activity::query()->where('event', 'accounting.connection-saved')->count())->toBe(1);

    $this->actingAs($owner)->get(route('settings.accounting.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.hasApiKey', true)
            ->where('connection.enabled', true)
            ->missing('connection.apiKey'));
});

it('keeps the saved key when the key field is left blank', function () {
    [$owner, $property] = accountingOwner();
    AccountingConnection::factory()->for($property)->create(['api_key' => 'saved-key', 'enabled' => false]);

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['api_key' => null, 'guest_ledger_account_code' => '400-000']))
        ->assertSessionHasNoErrors();

    $connection = $property->refresh()->accountingConnection;

    expect($connection->api_key)->toBe('saved-key')->and($connection->guest_ledger_account_code)->toBe('400-000');
});

it('saves the connection as off when the switch sends nothing', function () {
    [$owner, $property] = accountingOwner();
    $payload = connectionPayload();
    unset($payload['enabled']);

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), $payload)
        ->assertSessionHasNoErrors();

    expect($property->refresh()->accountingConnection->enabled)->toBeFalse();
});

it('saves the Bukku company subdomain and test server, and drops them for QNE', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['provider' => 'bukku', 'company_subdomain' => 'seaview', 'sandbox' => true]))
        ->assertSessionHasNoErrors();

    $connection = $property->refresh()->accountingConnection;
    expect($connection->provider)->toBe(AccountingProvider::Bukku)
        ->and($connection->company_subdomain)->toBe('seaview')
        ->and($connection->sandbox)->toBeTrue();

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['provider' => 'qne', 'company_subdomain' => 'seaview', 'sandbox' => true]))
        ->assertSessionHasNoErrors();

    $connection->refresh();
    expect($connection->company_subdomain)->toBeNull()->and($connection->sandbox)->toBeFalse();
});

it('needs the Bukku subdomain to turn the connection on and rejects a bad one', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['provider' => 'bukku', 'company_subdomain' => null]))
        ->assertSessionHasErrors('company_subdomain');

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['provider' => 'bukku', 'company_subdomain' => 'https://seaview.bukku.my']))
        ->assertSessionHasErrors('company_subdomain');
});

it('drops the saved key when the provider changes and needs a new one to turn it on', function () {
    [$owner, $property] = accountingOwner();
    AccountingConnection::factory()->for($property)->create(['api_key' => 'qne-key', 'enabled' => false]);

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['provider' => 'bukku', 'api_key' => null, 'company_subdomain' => 'seaview', 'enabled' => true]))
        ->assertSessionHasErrors('api_key');

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['provider' => 'bukku', 'api_key' => null, 'company_subdomain' => 'seaview', 'enabled' => false]))
        ->assertSessionHasNoErrors();

    expect($property->refresh()->accountingConnection->api_key)->toBeNull();
});

it('turns company billing on only together with the daily journal', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['enabled' => false, 'push_company_billing' => true]))
        ->assertSessionHasErrors('push_company_billing');

    expect($property->refresh()->accountingConnection)->toBeNull();
});

it('starts company billing from the current business date and keeps that date when it is switched off and on', function () {
    [$owner, $property] = accountingOwner();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['push_company_billing' => true]))
        ->assertSessionHasNoErrors();

    $connection = $property->refresh()->accountingConnection;
    expect($connection->push_company_billing)->toBeTrue()
        ->and($connection->company_billing_since->toDateString())->toBe('2026-10-12');

    $this->actingAs($owner)->put(route('settings.accounting.update', $property->code), connectionPayload(['push_company_billing' => false]));
    $this->actingAs($owner)->put(route('settings.accounting.update', $property->code), connectionPayload(['push_company_billing' => true]));

    $connection->refresh();
    expect($connection->push_company_billing)->toBeTrue()
        ->and($connection->company_billing_since->toDateString())->toBe('2026-10-12');
});

it('shows the company documents on the history tab', function () {
    [$owner, $property] = accountingOwner();
    $invoice = ArInvoice::factory()->create(['property_id' => $property->id, 'number' => 'INV-000042', 'amount' => '500.00']);
    AccountingDocument::factory()->create(['ar_invoice_id' => $invoice->id, 'property_id' => $property->id, 'external_id' => 'inv-7']);

    $this->actingAs($owner)->get(route('settings.accounting.history', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('documents', 1)
            ->where('documents.0.number', 'INV-000042')
            ->where('documents.0.kindLabel', 'Invoice')
            ->where('documents.0.status.value', 'pushed')
            ->where('documents.0.externalId', 'inv-7'));
});

it('sends company billing now from the history tab', function () {
    [$owner, $property] = accountingOwner();
    AccountingConnection::factory()->for($property)->create(['push_company_billing' => true, 'company_billing_since' => '2026-10-01']);

    $this->actingAs($owner)->post(route('settings.accounting.billing.sync', $property->code))
        ->assertRedirect(route('settings.accounting.history', $property->code));

    expect(AccountingDocument::query()->count())->toBe(0);
});

it('refuses to send company billing now when it is not switched on', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)->post(route('settings.accounting.billing.sync', $property->code))
        ->assertRedirect(route('settings.accounting.history', $property->code));

    expect(AccountingDocument::query()->count())->toBe(0);
});

it('refuses to turn the connection on without a key or a guest ledger account', function () {
    [$owner, $property] = accountingOwner();

    $this->actingAs($owner)
        ->put(route('settings.accounting.update', $property->code), connectionPayload(['api_key' => null, 'guest_ledger_account_code' => null]))
        ->assertSessionHasErrors(['api_key', 'guest_ledger_account_code']);

    expect($property->refresh()->accountingConnection)->toBeNull();
});

it('saves account mappings for this property only', function () {
    [$owner, $property] = accountingOwner();
    $room = TransactionCode::query()->where('property_id', $property->id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->firstOrFail();
    [, $other] = accountingOwner();
    $otherRoom = TransactionCode::query()->where('property_id', $other->id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->firstOrFail();

    $this->actingAs($owner)
        ->put(route('settings.accounting.mappings', $property->code), ['accounts' => [$room->id => ' 500-000 ', $otherRoom->id => '999-999']])
        ->assertSessionHasNoErrors();

    expect($room->refresh()->gl_account_code)->toBe('500-000')
        ->and($otherRoom->refresh()->gl_account_code)->toBeNull();

    $this->actingAs($owner)
        ->put(route('settings.accounting.mappings', $property->code), ['accounts' => [$room->id => '']]);

    expect($room->refresh()->gl_account_code)->toBeNull();
});

it('tests the connection and reports a missing mapped account', function () {
    [$owner, $property] = accountingOwner();
    $connection = AccountingConnection::factory()->for($property)->create();
    TransactionCode::query()->where('property_id', $property->id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->update(['gl_account_code' => '500-000']);
    Http::fake([
        '*/api/Auth/Connect*' => Http::response(['code' => '0000', 'data' => ['token' => 'jwt']]),
        '*/api/AccountCodes/Leaf/Query*' => fn (Request $request) => Http::response(['code' => '0000', 'data' => [
            'count' => 1,
            'value' => str_contains($request['$filter'] ?? '', '500-000') ? [['id' => 'a']] : [],
        ]]),
    ]);

    $this->actingAs($owner)->post(route('settings.accounting.test', $property->code))
        ->assertRedirect(route('settings.accounting.edit', $property->code));

    expect($connection->refresh()->last_error)->toContain('300-000')
        ->and($connection->last_tested_at)->not->toBeNull();
});

it('pushes a failed date again from the sync history', function () {
    [$owner, $property] = accountingOwner();
    AccountingConnection::factory()->for($property)->create();
    $date = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $sync = AccountingSync::factory()->create([
        'property_id' => $property->id,
        'business_date_id' => $date->id,
        'status' => AccountingSyncStatus::Failed,
        'error' => 'QNE could not be reached.',
    ]);

    $this->actingAs($owner)->post(route('settings.accounting.retry', [$property->code, $sync]))
        ->assertRedirect(route('settings.accounting.history', $property->code));

    expect($sync->refresh()->status)->toBe(AccountingSyncStatus::Skipped);
});

it('does not push a date that is already pushed', function () {
    [$owner, $property] = accountingOwner();
    AccountingConnection::factory()->for($property)->create();
    $sync = AccountingSync::factory()->create(['property_id' => $property->id, 'status' => AccountingSyncStatus::Pushed, 'external_id' => 'journal-1']);

    $this->actingAs($owner)->post(route('settings.accounting.retry', [$property->code, $sync]));

    expect($sync->refresh()->status)->toBe(AccountingSyncStatus::Pushed)->and($sync->attempts)->toBe(0);
});

it('hides another property\'s sync and refuses staff without the settings permission', function () {
    [$owner, $property] = accountingOwner();
    [, $other] = accountingOwner();
    $foreign = AccountingSync::factory()->create(['property_id' => $other->id]);

    $this->actingAs($owner)->post(route('settings.accounting.retry', [$property->code, $foreign]))->assertNotFound();

    $housekeeper = User::factory()->for($property)->withRole(DefaultRoleEnum::Housekeeping)->create();
    $this->actingAs($housekeeper)->get(route('settings.accounting.edit', $property->code))->assertForbidden();
});
