<?php

namespace Database\Factories;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EInvoiceSubmission>
 */
class EInvoiceSubmissionFactory extends Factory
{
    protected $model = EInvoiceSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'folio_id' => null,
            'ar_invoice_id' => null,
            'reservation_id' => null,
            'company_id' => null,
            'original_submission_id' => null,
            'document_type' => EInvoiceDocumentType::Invoice,
            'scenario' => EInvoiceScenario::Folio,
            'status' => EInvoiceStatus::Valid,
            'internal_id' => 'INV-'.fake()->numerify('#####'),
            'uuid' => 'LHDN-'.fake()->uuid(),
            'submission_uid' => 'SUB-'.fake()->bothify('######??'),
            'long_id' => fake()->sha256(),
            'buyer_snapshot' => [
                'name' => fake()->name(),
                'tin' => 'EI00000000020',
                'id_type' => 'PASSPORT',
                'id_value' => fake()->bothify('??######'),
                'city' => 'Kuala Lumpur',
                'postal_code' => '50450',
                'state_code' => '14',
                'country_code' => 'MYS',
            ],
            'document_payload' => ['sample' => true],
            'raw_response' => ['status' => 'Valid'],
            'error_details' => null,
            'is_consolidated' => false,
            'requested_by_guest' => false,
            'submitted_at' => now()->subMinutes(10),
            'validated_at' => now()->subMinutes(8),
            'cancelled_at' => null,
        ];
    }
}
