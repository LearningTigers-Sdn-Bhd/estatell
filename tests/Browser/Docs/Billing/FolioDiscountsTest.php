<?php

use App\Actions\Folio\ApplyDiscount;
use App\Enums\DiscountScope;
use App\Models\Discount;
use App\Services\Folio\DiscountQuote;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $this->staffRate = Discount::factory()->for($this->property)->create(['name' => 'Staff rate', 'value' => '10.00', 'scope' => DiscountScope::RoomOnly]);
    $this->goodwill = Discount::factory()->for($this->property)->open()->create(['name' => 'Goodwill', 'scope' => DiscountScope::AllCharges]);
});

/**
 * Open the folio tab of S11, in house for two nights with room, tax and breakfast lines and no discount yet.
 */
$openFolio = fn () => $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
    ->waitForText('Projected total')
    ->wait(1);

test('the apply discount button', function () use ($openFolio) {
    $this->shoot($openFolio->call($this), 'folio-discounts-button', badges: ['Apply discount']);
});

test('pick a discount', function () use ($openFolio) {
    $page = $openFolio->call($this)
        ->click('Apply discount')
        ->waitForText('The discount is posted under each charge it covers')
        ->click('#discount-discount_id')
        ->click('[role="option"]:has-text("Staff rate")')
        ->wait(1);

    $this->shoot($page, 'folio-discounts-pick', badges: ['Discount', 'Review discount'], frame: '[role="dialog"]');
});

test('an open discount asks for an amount', function () use ($openFolio) {
    $page = $openFolio->call($this)
        ->click('Apply discount')
        ->waitForText('The discount is posted under each charge it covers')
        ->click('#discount-discount_id')
        ->click('[role="option"]:has-text("Goodwill")')
        ->wait(1);

    $this->shoot($page, 'folio-discounts-amount', badges: ['Amount'], frame: '[role="dialog"]');
});

test('check the eligible charges', function () use ($openFolio) {
    $page = $openFolio->call($this)
        ->click('Apply discount')
        ->waitForText('The discount is posted under each charge it covers')
        ->click('#discount-discount_id')
        ->click('[role="option"]:has-text("Staff rate")')
        ->click('Review discount')
        ->waitForText('Total discount')
        ->wait(1);

    $this->shoot($page, 'folio-discounts-review', badges: ['Back', 'Apply discount'], frame: '[role="dialog"]');
});

/**
 * Apply the Staff rate to S11 the way the dialog does: ask for the quote, then apply it with its fingerprint.
 */
$applyStaffRate = function (): void {
    $folio = $this->scenario('S11')->folios()->firstOrFail();
    $quote = app(DiscountQuote::class)->quote($folio, $this->staffRate, CarbonImmutable::parse('2026-10-14'));

    app(ApplyDiscount::class)(auth()->user(), $folio, $this->staffRate, null, $quote->fingerprint);
};

test('a discount on the folio', function () use ($openFolio, $applyStaffRate) {
    $applyStaffRate->call($this);

    $this->shoot($openFolio->call($this), 'folio-discounts-applied');
});

test('the remove discount menu item', function () use ($openFolio, $applyStaffRate) {
    $applyStaffRate->call($this);

    $page = $openFolio->call($this)
        ->click('button[aria-label="Actions for Staff rate"] >> nth=0')
        ->waitForText('Remove discount')
        ->wait(1);

    $this->shoot($page, 'folio-discounts-remove-menu', badges: ['Remove discount']);
});

test('remove a discount', function () use ($openFolio, $applyStaffRate) {
    $applyStaffRate->call($this);

    $page = $openFolio->call($this)
        ->click('button[aria-label="Actions for Staff rate"] >> nth=0')
        ->click('Remove discount')
        ->waitForText('The discount and its tax lines are reversed.')
        ->wait(1);

    $this->shoot($page, 'folio-discounts-remove', frame: '[role="alertdialog"]');
});
