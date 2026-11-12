<?php

use App\Enums\EInvoiceEnvironment;
use App\Enums\FolioType;
use App\Enums\IdentityDocumentType;
use App\Enums\TransactionKind;
use App\Features\EInvoicing;
use App\Models\BusinessDate;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Setup a property with live status, open business date, and active mock E-Invoicing.
 *
 * @return array{
 *     owner: User,
 *     property: Property,
 *     setting: PropertyEInvoiceSetting,
 *     reservation: Reservation,
 *     folio: Folio
 * }
 */
function submissionSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    BusinessDate::factory()->create(['property_id' => $property->id]);

    Feature::for($property)->activate(EInvoicing::class);

    $setting = PropertyEInvoiceSetting::factory()->for($property)->create([
        'enabled' => true,
        'api_environment' => EInvoiceEnvironment::Mock,
        'client_id' => 'mock-client-id',
        'client_secret' => 'mock-client-secret',
        'supplier_name' => 'Mock Grand Hotel Sdn Bhd',
        'supplier_tin' => 'C1234567890',
        'supplier_sst_registration' => 'W10-1808-12345678',
        'supplier_msic_code' => '55101',
        'supplier_business_description' => 'Hotel operations',
        'supplier_email' => 'accounts@hotel.my',
        'supplier_city' => 'Kuala Lumpur',
    ]);

    $guest = Guest::factory()->for($property)->create([
        'full_name' => 'Ahmad Razak',
        'tin' => 'IG1234567890',
        'city' => 'Kuala Lumpur',
        'email' => 'ahmad@example.my',
        'phone' => '+60123456789',
        'state_code' => '14',
    ]);

    GuestIdentityDocument::factory()->for($guest)->create(['type' => IdentityDocumentType::MyKad, 'number' => '900101125555']);

    $reservation = Reservation::factory()->for($property)->create([
        'booker_guest_id' => $guest->id,
    ]);

    $folio = Folio::factory()->for($property)->closed()->create([
        'reservation_id' => $reservation->id,
        'payer_guest_id' => $guest->id,
        'type' => FolioType::Master,
    ]);

    $chargeCode = TransactionCode::factory()->create([
        'property_id' => $property->id,
        'category' => 'room',
    ]);

    FolioLine::factory()->for($folio)->create([
        'transaction_code_id' => $chargeCode->id,
        'kind' => TransactionKind::Charge,
        'amount' => '350.00',
        'unit_amount' => '350.00',
        'description' => 'Deluxe King Room',
    ]);

    return [
        'owner' => $owner,
        'property' => $property,
        'setting' => $setting,
        'reservation' => $reservation,
        'folio' => $folio,
    ];
}

/**
 * Submit the setup folio's e-invoice and return the stored submission.
 *
 * @param  array{owner: User, property: Property, reservation: Reservation, folio: Folio}  $setup
 */
function submitSetupFolio(array $setup): EInvoiceSubmission
{
    test()->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'success');

    return EInvoiceSubmission::where('folio_id', $setup['folio']->id)->firstOrFail();
}
