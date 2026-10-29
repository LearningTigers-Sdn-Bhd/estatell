<?php

use App\Enums\PaymentMethodType;
use Illuminate\Support\Facades\File;
use Tests\Browser\Docs\Companies\ArInvoicesAndPayments\ArFixture;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Open the Record payment dialog of the company with Bank transfer chosen.
 */
$openDialog = function () {
    $company = ArFixture::company($this->property);

    return $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]), height: 1000)
        ->click('button:has-text("Record payment")')
        ->waitForText('Money received from the company')
        ->click('#ar-method')
        ->click('[role="option"]:has-text("Bank transfer")')
        ->waitForText('Reference');
};

test('the payments tab', function () {
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, ArFixture::company($this->property)]))
        ->waitForText('No payments yet')
        ->wait(1);

    $this->shoot($page, 'record-company-payment-closed', badges: ['Record payment']);
});

test('the record payment dialog', function () use ($openDialog) {
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));

    $page = $openDialog->call($this);
    $page->fill('#ar-amount', '1517.96')
        ->type('#ar-reference', 'MBB-88231')
        ->type('#ar-notes', 'Bank transfer for the sales retreat')
        ->wait(1);

    $this->shoot($page, 'record-company-payment-dialog', badges: ['Method', 'Amount', 'Received on', 'Reference', 'Notes', 'Allocate to oldest invoices'], frame: '[role="dialog"]');
});

test('the reference warning', function () use ($openDialog) {
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay(ArFixture::company($this->property), PaymentMethodType::BankTransfer, '200.00', 'MBB-88231');

    $page = $openDialog->call($this);
    $page->fill('#ar-amount', '200.00')
        ->type('#ar-reference', 'MBB-88231')
        ->waitForText('was already recorded')
        ->wait(1);

    $this->shoot($page, 'record-company-payment-warning', frame: '[role="dialog"]');
});

test('the payments tab with payments', function () {
    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay($company, PaymentMethodType::BankTransfer, '1517.96', 'MBB-88231', allocate: true);
    ArFixture::pay($company, PaymentMethodType::Cash, '500.00');

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]))
        ->waitForText('MBB-88231')
        ->wait(1);

    $this->shoot($page, 'record-company-payment-list');
});

test('the payment row menu', function () {
    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay($company, PaymentMethodType::BankTransfer, '500.00', 'MBB-88231');

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]))
        ->waitForText('MBB-88231')
        ->click('button[aria-label^="Actions for"]')
        ->waitForText('Receipt')
        ->wait(1);

    $this->shoot($page, 'record-company-payment-row-menu', badges: ['Allocate', 'Receipt', 'Void']);
});

test('the receipt', function () {
    if (trim((string) shell_exec('command -v pdftoppm')) === '') {
        $this->markTestSkipped('pdftoppm (poppler) is needed to turn the receipt PDF into a PNG.');
    }

    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    $payment = ArFixture::pay($company, PaymentMethodType::BankTransfer, '1517.96', 'MBB-88231', allocate: true);

    $response = $this->get(route('finance.city-ledger.companies.payments.receipt', [$this->property, $company, $payment]));
    expect($response->baseResponse->getStatusCode())->toBe(200);

    $folder = base_path('tests/Browser/Screenshots/companies/ar-invoices-and-payments');
    File::ensureDirectoryExists($folder);
    $pdf = $folder.'/record-company-payment-receipt.pdf';
    File::put($pdf, $response->baseResponse->getContent());

    exec('pdftoppm -png -r 110 -f 1 -l 1 -singlefile '.escapeshellarg($pdf).' '.escapeshellarg($folder.'/record-company-payment-receipt'));
    File::delete($pdf);

    $image = new Imagick($folder.'/record-company-payment-receipt.png');
    $image->cropImage($image->getImageWidth(), (int) ($image->getImageHeight() * 0.46), 0, 0);
    $image->setImagePage(0, 0, 0, 0);
    $image->writeImage($folder.'/record-company-payment-receipt.png');

    expect(File::exists($folder.'/record-company-payment-receipt.png'))->toBeTrue();
});
