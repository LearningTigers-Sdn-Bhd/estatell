<?php

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioType;
use App\Enums\TransactionKind;
use App\Features\EInvoicing;
use App\Models\BusinessDate;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

/**
 * Setup a property with live status, open business date, and active mock E-Invoicing.
 *
 * @return array{
 *     owner: User,
 *     property: Property,
 *     setting: PropertyEInvoiceSetting,
 *     chargeCode: TransactionCode
 * }
 */
function consolidationSetup(): array
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
        'consolidation_enabled' => true,
    ]);

    $chargeCode = TransactionCode::factory()->create([
        'property_id' => $property->id,
        'category' => 'room',
    ]);

    return [
        'owner' => $owner,
        'property' => $property,
        'setting' => $setting,
        'chargeCode' => $chargeCode,
    ];
}

it('consolidates unconsolidated folios from previous month into a batch submission', function () {
    $setup = consolidationSetup();
    $lastMonth = CarbonImmutable::now()->subMonth();

    // Create 3 closed folios from previous month
    for ($i = 1; $i <= 3; $i++) {
        $guest = Guest::factory()->for($setup['property'])->create();
        $reservation = Reservation::factory()->for($setup['property'])->create([
            'booker_guest_id' => $guest->id,
        ]);
        $folio = Folio::factory()->for($setup['property'])->closed()->create([
            'reservation_id' => $reservation->id,
            'payer_guest_id' => $guest->id,
            'type' => FolioType::Master,
            'closed_at' => $lastMonth->startOfMonth()->addDays($i),
        ]);
        FolioLine::factory()->for($folio)->create([
            'transaction_code_id' => $setup['chargeCode']->id,
            'kind' => TransactionKind::Charge,
            'amount' => '200.00',
            'posting_date' => $lastMonth->startOfMonth()->addDays($i)->toDateString(),
        ]);
    }

    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices")
        ->post("/p/{$setup['property']->code}/finance/e-invoices/consolidate", [
            'month' => $lastMonth->format('Y-m'),
        ])
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertInertiaFlash('toast.type', 'success');

    $consolidated = EInvoiceSubmission::where('property_id', $setup['property']->id)
        ->where('is_consolidated', true)
        ->first();

    expect($consolidated)->not->toBeNull();
    expect($consolidated->scenario)->toBe(EInvoiceScenario::Consolidation);
    expect($consolidated->document_type)->toBe(EInvoiceDocumentType::Invoice);
    expect($consolidated->status)->toBe(EInvoiceStatus::Valid);
    expect($consolidated->buyer_snapshot['tin'])->toBe('EI00000000010');
    expect($consolidated->buyer_snapshot['state_code'])->toBe('17');
    expect($consolidated->totalAmount())->toBe(600.0);
});

it('excludes folios exceeding RM 10,000 from monthly consolidation', function () {
    $setup = consolidationSetup();
    $lastMonth = CarbonImmutable::now()->subMonth();

    // 1 regular folio (RM 500)
    $guest1 = Guest::factory()->for($setup['property'])->create();
    $res1 = Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest1->id]);
    $folio1 = Folio::factory()->for($setup['property'])->closed()->create([
        'reservation_id' => $res1->id,
        'payer_guest_id' => $guest1->id,
        'closed_at' => $lastMonth->startOfMonth()->addDays(2),
    ]);
    FolioLine::factory()->for($folio1)->create([
        'transaction_code_id' => $setup['chargeCode']->id,
        'kind' => TransactionKind::Charge,
        'amount' => '500.00',
        'posting_date' => $lastMonth->startOfMonth()->addDays(2)->toDateString(),
    ]);

    // 1 luxury/long-stay folio exceeding RM 10,000 (RM 12,500)
    $guest2 = Guest::factory()->for($setup['property'])->create();
    $res2 = Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest2->id]);
    $folio2 = Folio::factory()->for($setup['property'])->closed()->create([
        'reservation_id' => $res2->id,
        'payer_guest_id' => $guest2->id,
        'closed_at' => $lastMonth->startOfMonth()->addDays(5),
    ]);
    FolioLine::factory()->for($folio2)->create([
        'transaction_code_id' => $setup['chargeCode']->id,
        'kind' => TransactionKind::Charge,
        'amount' => '12500.00',
        'posting_date' => $lastMonth->startOfMonth()->addDays(5)->toDateString(),
    ]);

    $action = app(ConsolidateMonthlyEInvoices::class);
    $results = $action($setup['property'], $lastMonth);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(EInvoiceStatus::Valid);

    $submission = $results[0];
    // Consolidated batch total should only be RM 500 (folio 1 only, folio 2 excluded)
    expect($submission->totalAmount())->toBe(500.0);
});

it('excludes folios that were already individually submitted to LHDN', function () {
    $setup = consolidationSetup();
    $lastMonth = CarbonImmutable::now()->subMonth();

    $guest = Guest::factory()->for($setup['property'])->create();
    $res = Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest->id]);
    $folio = Folio::factory()->for($setup['property'])->closed()->create([
        'reservation_id' => $res->id,
        'payer_guest_id' => $guest->id,
        'closed_at' => $lastMonth->startOfMonth()->addDays(3),
    ]);
    FolioLine::factory()->for($folio)->create([
        'transaction_code_id' => $setup['chargeCode']->id,
        'kind' => TransactionKind::Charge,
        'amount' => '300.00',
        'posting_date' => $lastMonth->startOfMonth()->addDays(3)->toDateString(),
    ]);

    // Create an individual submission for this folio
    EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $folio->id,
        'document_type' => EInvoiceDocumentType::Invoice,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Valid,
    ]);

    $action = app(ConsolidateMonthlyEInvoices::class);
    $results = $action($setup['property'], $lastMonth);

    // No folios remain eligible for consolidation
    expect($results)->toBeEmpty();
});

it('consolidates a closed folio whose payments brought its balance to 0', function () {
    $setup = consolidationSetup();
    $lastMonth = CarbonImmutable::now()->subMonth();
    $guest = Guest::factory()->for($setup['property'])->create();
    $folio = Folio::factory()->for($setup['property'])->closed()->create([
        'reservation_id' => Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest->id])->id,
        'payer_guest_id' => $guest->id,
        'type' => FolioType::Master,
        'closed_at' => $lastMonth->startOfMonth()->addDays(3),
    ]);
    $postingDate = $lastMonth->startOfMonth()->addDays(2)->toDateString();
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $setup['chargeCode']->id, 'kind' => TransactionKind::Charge, 'amount' => '200.00', 'posting_date' => $postingDate]);
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $setup['chargeCode']->id, 'kind' => TransactionKind::Tax, 'amount' => '16.00', 'posting_date' => $postingDate]);
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $setup['chargeCode']->id, 'kind' => TransactionKind::Payment, 'amount' => '-216.00', 'posting_date' => $postingDate]);

    $batches = app(ConsolidateMonthlyEInvoices::class)($setup['property'], $lastMonth);

    expect($batches)->toHaveCount(1)
        ->and($batches[0]->totalAmount())->toBe(216.0);
});

it('lets the next run consolidate stays from a batch LHDN rejected', function () {
    $setup = consolidationSetup();
    $lastMonth = CarbonImmutable::now()->subMonth();
    $guest = Guest::factory()->for($setup['property'])->create();
    $folio = Folio::factory()->for($setup['property'])->closed()->create([
        'reservation_id' => Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest->id])->id,
        'payer_guest_id' => $guest->id,
        'type' => FolioType::Master,
        'closed_at' => $lastMonth->startOfMonth()->addDays(3),
    ]);
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $setup['chargeCode']->id, 'kind' => TransactionKind::Charge, 'amount' => '200.00', 'posting_date' => $lastMonth->startOfMonth()->addDays(2)->toDateString()]);
    $rejected = EInvoiceSubmission::factory()->for($setup['property'])->create(['scenario' => EInvoiceScenario::Consolidation, 'is_consolidated' => true, 'status' => EInvoiceStatus::Invalid]);
    $rejected->folios()->attach($folio->id);

    $batches = app(ConsolidateMonthlyEInvoices::class)($setup['property'], $lastMonth);

    expect($batches)->toHaveCount(1)
        ->and($batches[0]->folios()->pluck('folios.id')->all())->toBe([$folio->id]);
});

it('consolidates the chosen month even late in the current month', function () {
    $setup = consolidationSetup();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Asia/Kuala_Lumpur'));
    $guest = Guest::factory()->for($setup['property'])->create();
    $folio = Folio::factory()->for($setup['property'])->closed()->create([
        'reservation_id' => Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest->id])->id,
        'payer_guest_id' => $guest->id,
        'type' => FolioType::Master,
        'closed_at' => CarbonImmutable::parse('2026-02-10 12:00'),
    ]);
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $setup['chargeCode']->id, 'kind' => TransactionKind::Charge, 'amount' => '120.00', 'posting_date' => '2026-02-09']);

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/finance/e-invoices/consolidate", ['month' => '2026-02'])
        ->assertInertiaFlash('toast.type', 'success');

    expect(EInvoiceSubmission::query()->where('is_consolidated', true)->first()?->folios()->pluck('folios.id')->all())->toBe([$folio->id]);
});
