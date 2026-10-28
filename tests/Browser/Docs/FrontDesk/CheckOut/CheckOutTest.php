<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\TransferLines;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\TransactionKind;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('check out dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S11')]))
        ->click('More')
        ->wait(1);

    $this->shoot($page, 'check-out-menu', badges: ['Check out']);

    $page->click('Check out')
        ->waitForText('Collect and check out');

    $this->shoot($page, 'check-out', frame: '[role="dialog"]');
});

/**
 * Record a cash payment on S11, which departs today owing MYR 396.00, and open its check-out form.
 */
$openWithPayment = function (PaymentPurpose $purpose, string $amount, string $waitFor): AwaitableWebpage {
    $reservation = $this->scenario('S11');
    $cash = PaymentMethod::query()->where('property_id', $this->property->id)->where('type', PaymentMethodType::Cash)->where('active', true)->firstOrFail();

    app(RecordPayment::class)(auth()->user(), $reservation, null, $reservation->units()->firstOrFail(), new NewPaymentData($cash->id, $purpose, $amount, null, (string) Str::uuid()));

    return $this->open(route('reservations.show', [$this->property, $reservation]))
        ->click('More')
        ->click('Check out')
        ->waitForText($waitFor);
};

$openWithDeposit = fn (): AwaitableWebpage => $openWithPayment->call($this, PaymentPurpose::SecurityDeposit, '100.00', 'Security deposit');

test('guest paid more than the bill', function () use ($openWithPayment) {
    $this->shoot($openWithPayment->call($this, PaymentPurpose::Advance, '500.00', 'Refund and check out'), 'balance-credit', frame: '[role="dialog"]');
});

test('bill already settled', function () use ($openWithPayment) {
    $this->shoot($openWithPayment->call($this, PaymentPurpose::Advance, '396.00', 'Settled'), 'balance-settled', frame: '[role="dialog"]');
});

test('deposit used toward the bill', function () use ($openWithDeposit) {
    $this->shoot($openWithDeposit->call($this), 'deposit-use-toward-bill', frame: '[role="dialog"]');
});

test('deposit refunded', function () use ($openWithDeposit) {
    $page = $openWithDeposit->call($this)
        ->click('Refund it')
        ->waitForText('Refund by');

    $this->shoot($page, 'deposit-refund', frame: '[role="dialog"]');
});

test('part of the deposit kept for damages', function () use ($openWithDeposit) {
    $page = $openWithDeposit->call($this)
        ->click('Keep part for damages')
        ->type('#check-out-damage_amount', '50.00')
        ->type('#check-out-damage_note', 'Broken lamp');

    $this->shoot($page->wait(1), 'deposit-damages', frame: '[role="dialog"]');
});

test('new third-party folio dialog', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->click('More actions')
        ->click('Third-party folio')
        ->waitForText('New third-party folio');

    $this->shoot($page, 'pay-later-third-party-folio', frame: '[role="dialog"]');
});

test('company folio settled to the city ledger', function () {
    $reservation = $this->scenario('S11');
    $company = Company::query()->where('property_id', $this->property->id)->where('direct_bill_enabled', true)->firstOrFail();
    $unitFolio = Folio::query()->where('reservation_id', $reservation->id)->where('type', FolioType::Unit)->firstOrFail();
    $companyFolio = app(CreateFolio::class)->thirdParty($reservation, null, $company->id, auth()->user());
    $charges = FolioLine::query()->where('folio_id', $unitFolio->id)->where('kind', TransactionKind::Charge)->whereNull('parent_line_id')->whereNull('reversal_of_id')->get()->all();

    app(TransferLines::class)(auth()->user(), $charges, $companyFolio);

    $url = route('reservations.folio', [$this->property, $reservation, 'folio' => $companyFolio->id]);

    $this->shoot($this->open($url)->waitForText('Borneo Ventures Sdn Bhd'), 'pay-later-folios', badges: ['Unit 103', 'Settle to City ledger'], highlight: ['button:has(.lucide-landmark)']);

    $page = $this->open($url)
        ->click('button:has-text("Settle to City ledger")')
        ->waitForText('to City ledger?');

    $this->shoot($page, 'pay-later-settle-city-ledger', frame: '[role="alertdialog"]');
});
