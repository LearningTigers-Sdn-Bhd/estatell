<?php

use App\Actions\CityLedger\VoidArInvoice;
use App\Enums\PaymentMethodType;
use Illuminate\Support\Facades\File;
use Tests\Browser\Docs\Companies\ArInvoicesAndPayments\ArFixture;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Turn a PDF response into a PNG in this page's screenshot folder, cropped to the top part of the first page.
 */
$pdfShot = function (string $url, string $name, float $keep) {
    if (trim((string) shell_exec('command -v pdftoppm')) === '') {
        test()->markTestSkipped('pdftoppm (poppler) is needed to turn the PDF into a PNG.');
    }

    $response = test()->get($url);
    expect($response->baseResponse->getStatusCode())->toBe(200);

    $folder = base_path('tests/Browser/Screenshots/companies/ar-invoices-and-payments');
    File::ensureDirectoryExists($folder);
    $pdf = $folder.'/'.$name.'.pdf';
    File::put($pdf, $response->baseResponse->getContent());

    exec('pdftoppm -png -r 110 -f 1 -l 1 -singlefile '.escapeshellarg($pdf).' '.escapeshellarg($folder.'/'.$name));
    File::delete($pdf);

    $image = new Imagick($folder.'/'.$name.'.png');
    $image->cropImage($image->getImageWidth(), (int) ($image->getImageHeight() * $keep), 0, 0);
    $image->setImagePage(0, 0, 0, 0);
    $image->writeImage($folder.'/'.$name.'.png');

    expect(File::exists($folder.'/'.$name.'.png'))->toBeTrue();
};

test('the invoices list', function () {
    [$first, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::credit($second, '35.00', 'Minibar charged twice.');
    ArFixture::allocate(ArFixture::pay(ArFixture::company($this->property), PaymentMethodType::BankTransfer, '1517.96', 'MBB-88231'), [$first->id => '1517.96']);

    $page = $this->open(route('finance.city-ledger.invoices.index', $this->property))
        ->waitForText('Credit note')
        ->wait(1);

    $this->shoot($page, 'invoices-list', badges: ['All types', 'All statuses', 'Due']);
});

test('the invoices list with a filter', function () {
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));

    $page = $this->open(route('finance.city-ledger.invoices.index', [$this->property, 'filter' => ['status' => 'open']]))
        ->wait(1);

    $this->shoot($page, 'invoices-list-open');
});

test('the invoice page', function () {
    [$first] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::credit($first, '50.00', 'Goodwill for a noisy room.');
    ArFixture::allocate(ArFixture::pay(ArFixture::company($this->property), PaymentMethodType::BankTransfer, '500.00', 'MBB-88231'), [$first->id => '500.00']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $first]), height: 1300)
        ->waitForText('Folio lines')
        ->wait(1);

    $this->shoot($page, 'invoice-page', badges: ['Download PDF', 'Issue credit note', 'Payments', 'Folio lines']);
});

test('the invoice pdf', function () use ($pdfShot) {
    [$first] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));

    $pdfShot(route('finance.city-ledger.invoices.pdf', [$this->property, $first]), 'invoice-pdf', 0.72);
});

test('the pdf of a void invoice', function () use ($pdfShot) {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    expect(app(VoidArInvoice::class)(auth()->user(), $second)->isRefused())->toBeFalse();

    $pdfShot(route('finance.city-ledger.invoices.pdf', [$this->property, $second]), 'invoice-pdf-void', 0.45);
});

test('the void dialog', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));

    $url = route('finance.city-ledger.invoices.show', [$this->property, $second]);

    $this->shoot($this->open($url)->waitForText('Folio lines')->wait(1), 'invoice-void-button', badges: ['Void']);

    $page = $this->open($url)
        ->click('button:text-is("Void")')
        ->waitForText('reopen it, reverse the City ledger line')
        ->wait(1);

    $this->shoot($page, 'invoice-void-dialog', frame: '[role="alertdialog"]');
});

test('void is refused while a payment is allocated', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::allocate(ArFixture::pay(ArFixture::company($this->property), PaymentMethodType::BankTransfer, '30.00', 'MBB-90001'), [$second->id => '30.00']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $second]))
        ->waitForText('Folio lines')
        ->click('button:text-is("Void")')
        ->waitForText('reopen it, reverse the City ledger line')
        ->click('[role="alertdialog"] button[type="submit"]')
        ->waitForText('Only an open invoice')
        ->wait(1);

    $this->shoot($page, 'invoice-void-refused');
});
