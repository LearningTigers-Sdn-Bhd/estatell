<?php

use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use App\Services\EInvoice\LhdnTaxType;
use App\Services\EInvoice\SupplierDetails;
use Inertia\Testing\AssertableInertia as Assert;

function posTaxLineFor(Tax $tax): FolioLine
{
    return new FolioLine(['transaction_code_id' => $tax->transaction_code_id, 'description' => 'Tax']);
}

it('reads the tax type from the tax before its code', function () {
    $property = Property::factory()->create();
    $byCode = Tax::factory()->for($property)->create(['code' => 'SST']);
    $explicit = Tax::factory()->for($property)->create(['code' => 'TTX', 'lhdn_tax_type' => LhdnTaxType::NOT_APPLICABLE]);
    $custom = Tax::factory()->for($property)->create(['code' => 'FNB_TAX', 'lhdn_tax_type' => LhdnTaxType::SERVICE_TAX]);
    $levy = Tax::factory()->for($property)->create(['code' => 'HERITAGE']);

    $type = app(LhdnTaxType::class);

    expect($type->for(posTaxLineFor($byCode)))->toBe('02')
        ->and($type->for(posTaxLineFor($explicit)))->toBe('06')
        ->and($type->for(posTaxLineFor($custom)))->toBe('02')
        ->and($type->for(posTaxLineFor($levy)))->toBe('06');
});

it('needs the SST registration number for any enabled tax of type 02, whatever its code', function () {
    $property = Property::factory()->create();
    Tax::factory()->for($property)->create(['code' => 'FNB_6', 'lhdn_tax_type' => '02', 'enabled' => true]);

    expect(app(SupplierDetails::class)->missing($property))->toHaveKey('supplier_sst_registration');

    Tax::query()->update(['enabled' => false]);
    expect(app(SupplierDetails::class)->missing($property))->not->toHaveKey('supplier_sst_registration');
});

it('still needs the SST number for a tax coded SST with no tax type, and not once it is marked not applicable', function () {
    $property = Property::factory()->create();
    $tax = Tax::factory()->for($property)->create(['code' => 'SST', 'enabled' => true]);

    expect(app(SupplierDetails::class)->missing($property))->toHaveKey('supplier_sst_registration');

    $tax->forceFill(['lhdn_tax_type' => '06'])->save();
    expect(app(SupplierDetails::class)->missing($property))->not->toHaveKey('supplier_sst_registration');
});

it('saves the tax type from the taxes settings and offers the three types', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $payload = ['name' => 'Food and beverage tax', 'code' => 'FNB', 'rate_type' => 'percent', 'rate' => '6.00', 'is_inclusive' => false, 'foreign_guests_only' => false, 'collect_at_check_in' => false, 'can_be_waived' => false, 'enabled' => true];

    $this->actingAs($owner)->post(route('settings.taxes.store', $property->code), $payload + ['lhdn_tax_type' => '02'])->assertSessionHasNoErrors();
    $tax = Tax::query()->where('code', 'FNB')->sole();
    expect($tax->lhdn_tax_type)->toBe('02');

    $this->put(route('settings.taxes.update', [$property->code, $tax]), $payload + ['lhdn_tax_type' => ''])->assertSessionHasNoErrors();
    expect($tax->fresh()->lhdn_tax_type)->toBeNull();

    $this->put(route('settings.taxes.update', [$property->code, $tax]), $payload + ['lhdn_tax_type' => '99'])->assertSessionHasErrors('lhdn_tax_type');
    expect($tax->fresh()->lhdn_tax_type)->toBeNull();

    $this->get(route('settings.taxes.index', $property->code))->assertInertia(fn (Assert $page) => $page
        ->where('lhdnTaxTypes', [['value' => '02', 'label' => 'Service Tax (SST)'], ['value' => '03', 'label' => 'Tourism Tax'], ['value' => '06', 'label' => 'Not applicable']]));
});
