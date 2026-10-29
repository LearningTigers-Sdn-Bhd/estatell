<?php

use App\Actions\Folio\PostCharge;
use App\Data\Folio\NewChargeData;
use App\Models\ChargeItem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the post charge button', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G2')]))
        ->waitForText('Projected total')
        ->wait(1);

    $this->shoot($page, 'post-charges-button', badges: ['Post charge']);
});

test('the post charge dialog', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G2')]))
        ->waitForText('Projected total')
        ->click('Post charge')
        ->waitForText('Add a charge from the catalogue.')
        ->click('#charge-choice')
        ->click('[role="option"]:has-text("Laundry bag")')
        ->type('#charge-description', 'Laundry bag, room 206')
        ->wait(1);

    $this->shoot($page, 'post-charges-dialog', badges: ['Folio', 'Room', 'Charge', 'Description', 'Quantity'], frame: '[role="dialog"]');
});

test('the correct price link', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->waitForText('Projected total')
        ->click('button[aria-label^="Actions for Room"] >> nth=0')
        ->waitForText('Correct price…')
        ->wait(1);

    $this->shoot($page, 'post-charges-correct-price', badges: ['Correct price…']);
});

/**
 * Post a Laundry bag on S11, the way staff do, so the folio has a charge to reverse.
 */
$postLaundryBag = function (): void {
    $reservation = $this->scenario('S11');
    $item = ChargeItem::query()->where('property_id', $this->property->id)->where('name', 'Laundry bag')->firstOrFail();

    app(PostCharge::class)(auth()->user(), $reservation->units()->firstOrFail(), new NewChargeData(
        chargeItemId: $item->id,
        transactionCodeId: null,
        description: null,
        quantity: 1,
        unitAmount: '25.00',
        idempotencyKey: 'manual:'.Str::uuid(),
        folioId: $reservation->folios()->firstOrFail()->id,
    ));
};

test('the reverse menu item', function () use ($postLaundryBag) {
    $postLaundryBag->call($this);

    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->waitForText('Projected total')
        ->click('button[aria-label="Actions for Laundry bag"]')
        ->waitForText('Reverse')
        ->wait(1);

    $this->shoot($page, 'post-charges-reverse-menu', badges: ['Reverse']);
});

test('the reverse dialog', function () use ($postLaundryBag) {
    $postLaundryBag->call($this);

    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->waitForText('Projected total')
        ->click('button[aria-label="Actions for Laundry bag"]')
        ->click('Reverse')
        ->waitForText('Reverse charge')
        ->wait(1);

    $this->shoot($page, 'post-charges-reverse', frame: '[role="alertdialog"]');
});

test('a reversed charge', function () use ($postLaundryBag) {
    $postLaundryBag->call($this);

    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->waitForText('Projected total')
        ->click('button[aria-label="Actions for Laundry bag"]')
        ->click('Reverse')
        ->click('Reverse charge')
        ->waitForText('-MYR 25.00')
        ->wait(1);

    $this->shoot($page, 'post-charges-reversed');
});
