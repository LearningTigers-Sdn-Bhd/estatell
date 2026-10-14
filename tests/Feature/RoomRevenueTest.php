<?php

use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function seedRoomRevenueProperty(Property $property): void
{
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    app(SeedTransactionCodes::class)($property, 'MY');
    app(SeedTaxes::class)($property, 'MY');
}

test('the page shows the room revenue taxes and a preview for local and foreign guests', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    seedRoomRevenueProperty($property);
    $property->taxes()->update(['enabled' => true]);
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $tourism = $property->taxes()->where('code', 'TTX')->sole();
    $unlinked = Tax::factory()->for($property)->create(['name' => 'Heritage levy', 'enabled' => false]);

    $this->actingAs($owner)
        ->get(route('settings.room-revenue.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/room-revenue/index')
            ->has('taxes', 3)
            ->where('taxIds', [$sst->id, $tourism->id])
            ->where('preview.sampleAmount', '100.00')
            ->where('preview.refusal', null)
            ->where('preview.localNet', '100.00')
            ->where('preview.lines', [
                ['taxId' => $sst->id, 'name' => 'Service Tax (SST)', 'inclusive' => false, 'localAmount' => '8.00', 'foreignAmount' => '8.00'],
                ['taxId' => $tourism->id, 'name' => 'Tourism tax', 'inclusive' => false, 'localAmount' => null, 'foreignAmount' => '10.00'],
            ])
            ->where('preview.localTotal', '108.00')
            ->where('preview.foreignTotal', '118.00')
            ->where('currency.code', 'MYR')
            ->where('navigation.contexts.3.groups.3.items.3.title', 'Room revenue')
            ->where('navigation.contexts.3.groups.3.items.3.active', true));

    expect($unlinked->applicableTransactionCodes()->count())->toBe(0);
});

test('the preview explains when included taxes exceed the sample price', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    seedRoomRevenueProperty($property);
    $levy = Tax::factory()->for($property)->create([
        'rate_type' => TaxRateType::Flat,
        'rate' => '150.00',
        'is_inclusive' => true,
        'foreign_guests_only' => false,
        'enabled' => true,
    ]);
    $levy->applicableTransactionCodes()->attach($property->transactionCodes()->where('system_key', SystemTransactionCode::RoomRevenue->value)->sole());

    $this->actingAs($owner)
        ->get(route('settings.room-revenue.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.refusal', 'The taxes included in the price are more than the sample room price.')
            ->where('preview.lines', []));
});

test('the preview endpoint calculates an unsaved selection without saving it', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    seedRoomRevenueProperty($property);
    $property->taxes()->update(['enabled' => true]);
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $tourism = $property->taxes()->where('code', 'TTX')->sole();
    $roomCode = $property->transactionCodes()->where('system_key', SystemTransactionCode::RoomRevenue->value)->sole();

    $this->actingAs($owner)
        ->postJson(route('settings.room-revenue.preview', $property->code), ['tax_ids' => [$tourism->id]])
        ->assertOk()
        ->assertJsonPath('localTotal', '100.00')
        ->assertJsonPath('foreignTotal', '110.00')
        ->assertJsonCount(1, 'lines');

    expect($roomCode->applicableTaxes()->pluck('taxes.id')->sort()->values()->all())->toBe([$sst->id, $tourism->id]);

    $this->postJson(route('settings.room-revenue.preview', $property->code), ['tax_ids' => [Tax::factory()->create()->id]])
        ->assertUnprocessable();
});

test('saving links the same taxes to every room revenue code and logs only real changes', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    seedRoomRevenueProperty($property);
    $sst = $property->taxes()->where('code', 'SST')->sole();
    $levy = Tax::factory()->for($property)->create();
    $url = route('settings.room-revenue.update', $property->code);

    $this->actingAs($owner)
        ->put($url, ['tax_ids' => [$levy->id, $sst->id]])
        ->assertRedirect(route('settings.room-revenue.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room revenue taxes saved.']);

    $roomCodes = $property->transactionCodes()->whereIn('system_key', SystemTransactionCode::roomRevenueKeys())->with('applicableTaxes')->get();

    expect($roomCodes)->toHaveCount(5)
        ->and($roomCodes->every(fn (TransactionCode $code): bool => $code->applicableTaxes->pluck('id')->sort()->values()->all() === collect([$sst->id, $levy->id])->sort()->values()->all()))->toBeTrue()
        ->and(Activity::query()->where('event', 'room_revenue.taxes_updated')->count())->toBe(1);

    $this->put($url, ['tax_ids' => [$sst->id, $levy->id]]);
    $this->put($url, ['tax_ids' => []]);

    expect($sst->applicableTransactionCodes()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'room_revenue.taxes_updated')->count())->toBe(2);
});

test('room revenue taxes must belong to the property', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    seedRoomRevenueProperty($property);
    $foreignTax = Tax::factory()->create();

    $this->actingAs($owner)
        ->put(route('settings.room-revenue.update', $property->code), ['tax_ids' => [$foreignTax->id]])
        ->assertSessionHasErrors('tax_ids.0');

    $this->put(route('settings.room-revenue.update', $property->code), [])
        ->assertSessionHasErrors('tax_ids');
});

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherProperty = Property::factory()->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.room-revenue.index', $frontDesk->property->code))
        ->assertForbidden();
    $this->put(route('settings.room-revenue.update', $frontDesk->property->code), ['tax_ids' => []])
        ->assertForbidden();
    $this->postJson(route('settings.room-revenue.preview', $frontDesk->property->code), ['tax_ids' => []])
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('settings.room-revenue.index', $otherProperty->code))
        ->assertNotFound();
});
