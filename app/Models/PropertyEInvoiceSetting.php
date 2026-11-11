<?php

namespace App\Models;

use App\Enums\EInvoiceEnvironment;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyEInvoiceSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $property_id
 * @property bool $enabled
 * @property EInvoiceEnvironment $api_environment
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property bool $signature_enabled
 * @property string|null $signing_certificate
 * @property string|null $signing_private_key
 * @property string|null $signing_passphrase
 * @property string|null $supplier_name
 * @property string|null $supplier_tin
 * @property string|null $supplier_brn
 * @property string|null $supplier_sst_registration
 * @property string|null $supplier_ttx_registration
 * @property string|null $supplier_msic_code
 * @property string|null $supplier_business_description
 * @property string|null $supplier_email
 * @property string|null $supplier_phone
 * @property string|null $supplier_address_line1
 * @property string|null $supplier_address_line2
 * @property string|null $supplier_city
 * @property string|null $supplier_postal_code
 * @property string|null $supplier_state_code
 * @property bool $auto_submit_on_checkout
 * @property bool $consolidation_enabled
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Property $property
 */
#[Fillable([
    'property_id',
    'enabled',
    'api_environment',
    'client_id',
    'client_secret',
    'signature_enabled',
    'signing_certificate',
    'signing_private_key',
    'signing_passphrase',
    'supplier_name',
    'supplier_tin',
    'supplier_brn',
    'supplier_sst_registration',
    'supplier_ttx_registration',
    'supplier_msic_code',
    'supplier_business_description',
    'supplier_email',
    'supplier_phone',
    'supplier_address_line1',
    'supplier_address_line2',
    'supplier_city',
    'supplier_postal_code',
    'supplier_state_code',
    'auto_submit_on_checkout',
    'consolidation_enabled',
    'effective_from',
])]
class PropertyEInvoiceSetting extends Model
{
    /** @use HasFactory<PropertyEInvoiceSettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'api_environment' => EInvoiceEnvironment::class,
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'signature_enabled' => 'boolean',
            'signing_private_key' => 'encrypted',
            'signing_passphrase' => 'encrypted',
            'auto_submit_on_checkout' => 'boolean',
            'consolidation_enabled' => 'boolean',
            'effective_from' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function isReadyToSubmit(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->api_environment === EInvoiceEnvironment::Mock) {
            return true;
        }

        return ! empty($this->client_id) && ! empty($this->client_secret);
    }
}
