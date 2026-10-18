<?php

use App\Enums\CompanyStatus;
use App\Enums\DefaultRoleEnum;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\StatementBuilder;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalSetup.php';

beforeEach(function () {
    $this->user = portalUser();
    $this->property = Property::factory()->create();
    [$this->company, $this->link] = portalLinkFor($this->user, $this->property);
});

function portalStatementsPageUrl(Property $property, array $query = []): string
{
    return route('portal.statements', ['property' => $property->code, ...$query]);
}

test('the statements page lists only this company\'s Open and Settled invoices at this property', function () {
    $otherCompany = Company::factory()->for($this->property)->create(['name' => 'Other Co']);
    $elsewhere = Property::factory()->create();
    $sameNameElsewhere = Company::factory()->for($elsewhere)->create(['name' => 'Borneo Travel']);

    ArInvoice::factory()->for($this->property)->create(['company_id' => $this->company->id, 'number' => 'AR-0087', 'issued_on' => '2026-10-01', 'amount' => '1200.00']);
    ArInvoice::factory()->for($this->property)->create(['company_id' => $this->company->id, 'number' => 'AR-0091', 'issued_on' => '2026-10-09', 'due_on' => '2026-11-08', 'amount' => '3000.00']);
    ArInvoice::factory()->for($this->property)->settled()->create(['company_id' => $this->company->id, 'number' => 'AR-0089', 'issued_on' => '2026-10-05', 'amount' => '500.00']);
    ArInvoice::factory()->for($this->property)->void()->create(['company_id' => $this->company->id, 'number' => 'AR-0090']);
    ArInvoice::factory()->for($this->property)->create(['company_id' => $otherCompany->id, 'number' => 'AR-0200']);
    ArInvoice::factory()->for($elsewhere)->create(['company_id' => $sameNameElsewhere->id, 'number' => 'AR-0300']);

    $this->actingAs($this->user)
        ->get(portalStatementsPageUrl($this->property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/statements')
            ->has('invoices.data', 3)
            ->where('invoices.data.0.number', 'AR-0091')
            ->where('invoices.data.0.balance', '3000.00')
            ->where('invoices.data.0.overdue', false)
            ->where('invoices.data.1.number', 'AR-0089')
            ->where('invoices.data.1.status.value', 'settled')
            ->where('invoices.data.2.number', 'AR-0087')
            ->missing('invoices.data.3')
        );
});

test('the statements page lists only this company\'s payments that are not void at this property', function () {
    $elsewhere = Property::factory()->create();
    $sameNameElsewhere = Company::factory()->for($elsewhere)->create(['name' => 'Borneo Travel']);

    ArPayment::factory()->for($this->property)->create(['company_id' => $this->company->id, 'receipt_number' => 'RCP-0040', 'received_on' => '2026-10-05', 'amount' => '1200.00', 'reference' => 'BT-PAY-77']);
    ArPayment::factory()->for($this->property)->voided()->create(['company_id' => $this->company->id, 'receipt_number' => 'RCP-0041', 'received_on' => '2026-10-06']);
    ArPayment::factory()->for($elsewhere)->create(['company_id' => $sameNameElsewhere->id, 'receipt_number' => 'RCP-0300']);

    $this->actingAs($this->user)
        ->get(portalStatementsPageUrl($this->property))
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.receiptNumber', 'RCP-0040')
            ->where('payments.data.0.reference', 'BT-PAY-77')
            ->where('payments.data.0.amount', '1200.00')
        );
});

test('the statement dates default to the first of the month to the business date and never go past it', function () {
    $today = app(ArInvoiceDirectory::class)->today($this->property);

    $this->actingAs($this->user)
        ->get(portalStatementsPageUrl($this->property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('statement.from', CarbonImmutable::parse($today)->startOfMonth()->toDateString())
            ->where('statement.to', $today)
        );

    $this->actingAs($this->user)
        ->get(portalStatementsPageUrl($this->property, ['from' => '2026-09-01', 'to' => '2099-01-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('statement.from', '2026-09-01')
            ->where('statement.to', $today)
        );
});

test('the statement lines and closing balance match the staff statement builder', function () {
    ArInvoice::factory()->for($this->property)->create(['company_id' => $this->company->id, 'number' => 'AR-0080', 'issued_on' => '2026-09-20', 'due_on' => '2026-10-20', 'amount' => '800.00']);
    ArPayment::factory()->for($this->property)->create(['company_id' => $this->company->id, 'receipt_number' => 'RCP-0040', 'received_on' => '2026-10-05', 'amount' => '300.00']);
    ArInvoice::factory()->for($this->property)->create(['company_id' => $this->company->id, 'number' => 'AR-0091', 'issued_on' => '2026-10-09', 'due_on' => '2026-11-08', 'amount' => '3000.00']);

    $expected = app(StatementBuilder::class)->build($this->company, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-14'));

    expect($expected->openingBalance)->toBe('800.00')
        ->and($expected->closingBalance)->toBe('3500.00');

    $this->actingAs($this->user)
        ->get(portalStatementsPageUrl($this->property, ['from' => '2026-10-01', 'to' => '2026-10-14']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('statement.from', '2026-10-01')
            ->where('statement.to', '2026-10-14')
            ->where('statement.openingBalance', $expected->openingBalance)
            ->where('statement.closingBalance', $expected->closingBalance)
            ->has('statement.lines', 2)
            ->where('statement.lines.0.number', 'RCP-0040')
            ->where('statement.lines.0.balance', '500.00')
            ->where('statement.lines.1.number', 'AR-0091')
            ->where('statement.lines.1.balance', '3500.00')
            ->where('statement.aging.total', $expected->aging->total)
        );
});

test('the statement, invoice and receipt PDFs open for the company\'s own documents', function () {
    $invoice = ArInvoice::factory()->for($this->property)->create(['company_id' => $this->company->id, 'issued_on' => '2026-10-09', 'amount' => '3000.00']);
    $payment = ArPayment::factory()->for($this->property)->create(['company_id' => $this->company->id, 'received_on' => '2026-10-05']);

    $this->actingAs($this->user)
        ->get(route('portal.statements.pdf', ['property' => $this->property->code, 'from' => '2026-10-01', 'to' => '2026-10-14']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->user)
        ->get(route('portal.statements.invoices.pdf', ['property' => $this->property->code, 'invoice' => $invoice->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($this->user)
        ->get(route('portal.statements.payments.receipt', ['property' => $this->property->code, 'payment' => $payment->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('a document of another company or another property is a 404', function (string $case) {
    $elsewhere = Property::factory()->create();
    $otherCompany = Company::factory()->for($this->property)->create(['name' => 'Other Co']);
    [$elsewhereCompany] = portalLinkFor($this->user, $elsewhere);

    [$invoice, $payment] = match ($case) {
        'another company at this property' => [
            ArInvoice::factory()->for($this->property)->create(['company_id' => $otherCompany->id]),
            ArPayment::factory()->for($this->property)->create(['company_id' => $otherCompany->id]),
        ],
        'own company at another property' => [
            ArInvoice::factory()->for($elsewhere)->create(['company_id' => $elsewhereCompany->id]),
            ArPayment::factory()->for($elsewhere)->create(['company_id' => $elsewhereCompany->id]),
        ],
    };

    $this->actingAs($this->user)
        ->get(route('portal.statements.invoices.pdf', ['property' => $this->property->code, 'invoice' => $invoice->id]))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->get(route('portal.statements.payments.receipt', ['property' => $this->property->code, 'payment' => $payment->id]))
        ->assertNotFound();
})->with(['another company at this property', 'own company at another property']);

test('a void invoice or payment has no PDF', function () {
    $invoice = ArInvoice::factory()->for($this->property)->void()->create(['company_id' => $this->company->id]);
    $payment = ArPayment::factory()->for($this->property)->voided()->create(['company_id' => $this->company->id]);

    $this->actingAs($this->user)
        ->get(route('portal.statements.invoices.pdf', ['property' => $this->property->code, 'invoice' => $invoice->id]))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->get(route('portal.statements.payments.receipt', ['property' => $this->property->code, 'payment' => $payment->id]))
        ->assertNotFound();
});

test('a Suspended company still sees its statements and PDFs', function () {
    $this->company->forceFill(['status' => CompanyStatus::Suspended])->save();
    $invoice = ArInvoice::factory()->for($this->property)->create(['company_id' => $this->company->id]);

    $this->actingAs($this->user)
        ->get(portalStatementsPageUrl($this->property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));

    $this->actingAs($this->user)
        ->get(route('portal.statements.invoices.pdf', ['property' => $this->property->code, 'invoice' => $invoice->id]))
        ->assertOk();
});

test('people without an Active link at this property do not see the statements', function (string $case) {
    $actor = match ($case) {
        'staff' => User::factory()->withRole(DefaultRoleEnum::Owner)->for($this->property)->create(),
        'company user without a link' => portalUser(),
        default => $this->user,
    };

    match ($case) {
        'pending link' => $this->link->forceFill(['status' => 'pending', 'accepted_at' => null])->save(),
        'revoked link' => $this->link->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save(),
        default => null,
    };

    $this->actingAs($actor)->get(portalStatementsPageUrl($this->property))->assertNotFound();
    $this->actingAs($actor)->get(route('portal.statements.pdf', ['property' => $this->property->code]))->assertNotFound();
})->with(['staff', 'company user without a link', 'pending link', 'revoked link']);
