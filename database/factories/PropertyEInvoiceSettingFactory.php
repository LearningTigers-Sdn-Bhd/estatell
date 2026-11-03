<?php

namespace Database\Factories;

use App\Enums\EInvoiceEnvironment;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyEInvoiceSetting>
 */
class PropertyEInvoiceSettingFactory extends Factory
{
    protected $model = PropertyEInvoiceSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'enabled' => true,
            'api_environment' => EInvoiceEnvironment::Sandbox,
            'client_id' => 'mock_client_id_'.fake()->uuid(),
            'client_secret' => 'mock_client_secret_'.fake()->uuid(),
            'signature_enabled' => false,
            'signing_certificate' => null,
            'signing_private_key' => null,
            'supplier_name' => fake()->company(),
            'supplier_tin' => 'C'.fake()->numerify('##########'),
            'supplier_sst_registration' => 'S10-'.fake()->numerify('####-########'),
            'supplier_ttx_registration' => null,
            'supplier_msic_code' => '55101',
            'supplier_business_description' => 'Hotel and resort operations',
            'supplier_email' => fake()->safeEmail(),
            'supplier_phone' => '+60123456789',
            'supplier_address_line1' => fake()->streetAddress(),
            'supplier_address_line2' => null,
            'supplier_city' => 'Kota Kinabalu',
            'supplier_postal_code' => '88000',
            'supplier_state_code' => '12',
            'auto_submit_on_checkout' => false,
            'consolidation_enabled' => true,
            'effective_from' => now()->subMonths(1),
        ];
    }
}
