<?php

use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\Folio;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Take a payment on S11, which is in house and unpaid, and return the folio tab to refund or void it on.
 */
function refundTakePayment(Reservation $reservation, Property $property, PaymentMethodType $type, string $amount, ?string $reference = null): string
{
    $method = PaymentMethod::query()->where('property_id', $property->id)->where('type', $type)->where('active', true)->firstOrFail();

    app(RecordPayment::class)(auth()->user(), $reservation, null, $reservation->units()->firstOrFail(), new NewPaymentData($method->id, PaymentPurpose::Settlement, $amount, $reference, (string) Str::uuid()));

    return route('reservations.folio', [$property, $reservation]);
}

/**
 * Open the "Actions" menu of the payment row on the folio tab.
 */
function refundOpenMenu(AwaitableWebpage $page, string $method): AwaitableWebpage
{
    return $page->waitForText($method)
        ->click('button[aria-label^="Actions for '.$method.'"]')
        ->waitForText('Download receipt')
        ->wait(1);
}

test('the payment row menu with refund and void', function () {
    $url = refundTakePayment($this->scenario('S11'), $this->property, PaymentMethodType::Cash, '100.00');

    $page = refundOpenMenu($this->open($url), 'Cash');

    $this->shoot($page, 'refund-row-menu', badges: ['Refund', 'Void payment']);
});

test('refund a payment', function () {
    $url = refundTakePayment($this->scenario('S11'), $this->property, PaymentMethodType::Cash, '100.00');

    $page = refundOpenMenu($this->open($url), 'Cash')
        ->click('[role="menuitem"]:has-text("Refund")')
        ->waitForText('Refund to')
        ->wait(1);

    $this->shoot($page, 'refund', frame: '[role="dialog"]');
});

test('refund to another method', function () {
    $url = refundTakePayment($this->scenario('S11'), $this->property, PaymentMethodType::Cash, '100.00');

    $page = refundOpenMenu($this->open($url), 'Cash')
        ->click('[role="menuitem"]:has-text("Refund")')
        ->waitForText('Refund to')
        ->wait(1)
        ->click('#refund-method')
        ->click('[role="option"]:has-text("Bank transfer")')
        ->waitForText('Reason')
        ->type('#refund-reason', 'Guest asked for a bank transfer')
        ->type('#refund-amount', '40.00')
        ->wait(1);

    $this->shoot($page, 'refund-other-method', frame: '[role="dialog"]');
});

test('cash is greyed out for a card payment', function () {
    $url = refundTakePayment($this->scenario('S11'), $this->property, PaymentMethodType::Card, '100.00', 'Visa ending 4242');

    $page = refundOpenMenu($this->open($url), 'Card')
        ->click('[role="menuitem"]:has-text("Refund")')
        ->waitForText('Refund to')
        ->wait(1)
        ->click('#refund-method')
        ->waitForText('Only a cash payment is refunded in cash.')
        ->wait(1);

    $this->shoot($page, 'refund-cash-greyed-out');
});

test('void a payment', function () {
    $url = refundTakePayment($this->scenario('S11'), $this->property, PaymentMethodType::Cash, '100.00');

    $page = refundOpenMenu($this->open($url), 'Cash')
        ->click('[role="menuitem"]:has-text("Void payment")')
        ->waitForText('Void Cash?')
        ->wait(1);

    $this->shoot($page, 'void-payment', frame: '[role="alertdialog"]');
});

test('refund credit', function () {
    $folio = Folio::query()->where('reservation_id', $this->scenario('G8')->id)->where('type', FolioType::Master)->firstOrFail();
    $url = route('reservations.folio', [$this->property, $this->scenario('G8'), 'folio' => $folio->id]);

    $page = $this->open($url)
        ->waitForText('Refund credit')
        ->wait(1);

    $this->shoot($page, 'refund-credit-closed', badges: ['Refund credit']);

    $page->click('button:has-text("Refund credit")')
        ->waitForText('newest payment first')
        ->wait(1);

    $this->shoot($page, 'refund-credit', frame: '[role="dialog"]');
});
