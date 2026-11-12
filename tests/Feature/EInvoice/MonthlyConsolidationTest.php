<?php

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioType;
use App\Enums\TaxRateType;
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
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\EInvoice\SubmissionRules;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
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
    expect((string) app(SubmissionRules::class)->total($consolidated))->toBe('600.00');
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
    expect((string) app(SubmissionRules::class)->total($submission))->toBe('500.00');
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
        ->and((string) app(SubmissionRules::class)->total($batches[0]))->toBe('216.00');
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

it('reports the SST and tourism tax in each stay of the monthly summary, not as exempt', function () {
    $setup = consolidationSetup();
    $lastMonth = CarbonImmutable::now()->subMonth();
    $sst = Tax::factory()->for($setup['property'])->create(['code' => 'SST', 'name' => 'Government tax']);
    $tourism = Tax::factory()->for($setup['property'])->create(['code' => 'TTX', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00']);
    $setup['property']->eInvoiceSetting->update(['supplier_ttx_registration' => '141-2017-10000123']);
    $postingDate = $lastMonth->startOfMonth()->addDays(2)->toDateString();
    $stay = function (array $taxes) use ($setup, $lastMonth, $postingDate): void {
        $guest = Guest::factory()->for($setup['property'])->create();
        $folio = Folio::factory()->for($setup['property'])->closed()->create([
            'reservation_id' => Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest->id])->id,
            'payer_guest_id' => $guest->id,
            'type' => FolioType::Master,
            'closed_at' => $lastMonth->startOfMonth()->addDays(3),
        ]);
        FolioLine::factory()->for($folio)->create(['transaction_code_id' => $setup['chargeCode']->id, 'kind' => TransactionKind::Charge, 'amount' => '200.00', 'posting_date' => $postingDate]);
        foreach ($taxes as [$tax, $amount]) {
            FolioLine::factory()->for($folio)->create(['transaction_code_id' => $tax->transaction_code_id, 'kind' => TransactionKind::Tax, 'amount' => $amount, 'posting_date' => $postingDate]);
        }
    };
    $stay([[$sst, '16.00'], [$tourism, '10.00']]);
    $stay([]);

    $document = app(ConsolidateMonthlyEInvoices::class)($setup['property'], $lastMonth)[0]->document_payload['Invoice'][0];
    $byType = collect($document['TaxTotal'][0]['TaxSubtotal'])->mapWithKeys(fn (array $subtotal): array => [
        $subtotal['TaxCategory'][0]['ID'][0]['_'] => [$subtotal['TaxableAmount'][0]['_'], $subtotal['TaxAmount'][0]['_']],
    ]);

    expect($document['TaxTotal'][0]['TaxAmount'][0]['_'])->toEqual(26)
        ->and($byType->all())->toEqual(['02' => [200, 16], '03' => [200, 10], 'E' => [200, 0]])
        ->and($document['LegalMonetaryTotal'][0]['TaxExclusiveAmount'][0]['_'])->toEqual(400)
        ->and($document['LegalMonetaryTotal'][0]['PayableAmount'][0]['_'])->toEqual(426)
        ->and($document['InvoiceLine'][0]['LineExtensionAmount'][0]['_'])->toEqual(200)
        ->and($document['InvoiceLine'][0]['TaxTotal'][0]['TaxAmount'][0]['_'])->toEqual(26);
});

it('lists the stays of a monthly summary with the amounts LHDN received, to check against the folios', function () {
    $setup = consolidationSetup();
    $lastMonth = CarbonImmutable::now()->subMonth();
    $sst = Tax::factory()->for($setup['property'])->create(['code' => 'SST']);
    $guest = Guest::factory()->for($setup['property'])->create(['full_name' => 'Aisyah Rahman']);
    $reservation = Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $guest->id]);
    $folio = Folio::factory()->for($setup['property'])->closed()->create([
        'reservation_id' => $reservation->id,
        'payer_guest_id' => $guest->id,
        'type' => FolioType::Master,
        'closed_at' => $lastMonth->startOfMonth()->addDays(3),
    ]);
    $postingDate = $lastMonth->startOfMonth()->addDays(2)->toDateString();
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $setup['chargeCode']->id, 'kind' => TransactionKind::Charge, 'amount' => '200.00', 'posting_date' => $postingDate]);
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $sst->transaction_code_id, 'kind' => TransactionKind::Tax, 'amount' => '16.00', 'posting_date' => $postingDate]);

    $summary = app(ConsolidateMonthlyEInvoices::class)($setup['property'], $lastMonth)[0];

    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$summary->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('submission.stays', 1)
            ->where('submission.stays.0.reservation_id', $reservation->id)
            ->where('submission.stays.0.guest', 'Aisyah Rahman')
            ->where('submission.stays.0.net', '200.00')
            ->where('submission.stays.0.tax', '16.00')
            ->where('submission.stays.0.total', '216.00')
            ->etc());
});
