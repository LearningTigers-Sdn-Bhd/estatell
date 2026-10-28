<?php

use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SurchargeType;
use App\Models\ChargeItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Give the Card method a 2% surcharge, posted through a "Card fee" charge item.
 */
function recordPaymentCardWithFee(Property $property): PaymentMethod
{
    $fee = ChargeItem::factory()->create(['property_id' => $property->id, 'name' => 'Card fee']);
    $card = PaymentMethod::query()->where('property_id', $property->id)->where('type', PaymentMethodType::Card)->where('active', true)->firstOrFail();
    $card->update(['surcharge_type' => SurchargeType::Percent, 'surcharge_value' => '2.00', 'surcharge_charge_item_id' => $fee->id]);

    return $card;
}

test('the record payment button', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->waitForText('Record payment')
        ->wait(1);

    $this->shoot($page, 'record-payment-closed', badges: ['Record payment']);
});

test('the record payment dialog', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->click('button:has-text("Record payment")')
        ->waitForText('Total to collect')
        ->wait(1)
        ->assertSeeIn('#payment-method', 'Cash');

    $this->shoot($page, 'record-payment', frame: '[role="dialog"]');
});

test('the record payment dialog with a card surcharge', function () {
    recordPaymentCardWithFee($this->property);

    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]), height: 1000)
        ->click('button:has-text("Record payment")')
        ->waitForText('Method');

    $page->click('#payment-method')
        ->click('[role="option"]:has-text("Card")')
        ->type('#payment-reference', 'Visa ending 4242')
        ->waitForText('Surcharge')
        ->wait(1);

    $this->shoot($page, 'record-payment-surcharge', frame: '[role="dialog"]');
});

test('a payment on a unit that has not started', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S2')]))
        ->waitForText('Not started')
        ->wait(1);

    $this->shoot($page, 'record-payment-not-started', badges: ['Record payment']);
});

test('the payment row menu', function () {
    $reservation = $this->scenario('S11');
    $cash = PaymentMethod::query()->where('property_id', $this->property->id)->where('type', PaymentMethodType::Cash)->where('active', true)->firstOrFail();
    app(RecordPayment::class)(auth()->user(), $reservation, null, $reservation->units()->firstOrFail(), new NewPaymentData($cash->id, PaymentPurpose::Settlement, '100.00', null, (string) Str::uuid()));

    $page = $this->open(route('reservations.folio', [$this->property, $reservation]))
        ->waitForText('Cash')
        ->click('button[aria-label^="Actions for Cash"]')
        ->waitForText('Download receipt')
        ->wait(1);

    $this->shoot($page, 'record-payment-row-menu', badges: ['Download receipt']);
});

test('the receipt', function () {
    if (trim((string) shell_exec('command -v pdftoppm')) === '') {
        $this->markTestSkipped('pdftoppm (poppler) is needed to turn the receipt PDF into a PNG.');
    }

    $card = recordPaymentCardWithFee($this->property);
    $reservation = $this->scenario('S11');
    $result = app(RecordPayment::class)(auth()->user(), $reservation, null, $reservation->units()->firstOrFail(), new NewPaymentData($card->id, PaymentPurpose::Settlement, '200.00', 'Visa ending 4242', (string) Str::uuid()));
    $payment = Payment::query()->findOrFail($result->payment->id);

    $response = $this->get(route('reservations.folios.payments.receipt', [$this->property, $reservation, $payment->folio_id, $payment]));
    expect($response->baseResponse->getStatusCode())->toBe(200);

    $folder = base_path('tests/Browser/Screenshots/billing/payments-and-refunds');
    File::ensureDirectoryExists($folder);
    $pdf = $folder.'/receipt.pdf';
    File::put($pdf, $response->baseResponse->getContent());

    exec('pdftoppm -png -r 110 -f 1 -l 1 -singlefile '.escapeshellarg($pdf).' '.escapeshellarg($folder.'/receipt'));
    File::delete($pdf);

    $image = new Imagick($folder.'/receipt.png');
    $image->cropImage($image->getImageWidth(), (int) ($image->getImageHeight() * 0.62), 0, 0);
    $image->setImagePage(0, 0, 0, 0);
    $image->writeImage($folder.'/receipt.png');

    expect(File::exists($folder.'/receipt.png'))->toBeTrue();
});
