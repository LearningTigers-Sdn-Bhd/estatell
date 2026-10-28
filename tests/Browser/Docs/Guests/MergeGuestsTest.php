<?php

use App\Actions\Guests\AddIdentityDocument;
use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\IdentityDocumentType;
use App\Models\Guest;
use App\Models\GuestNote;

/**
 * The card reader bridge is a program on the front-desk PC, so the ID form must not depend on it.
 * The stub is wrapped so the script returns nothing: the browser driver calls a returned function.
 */
const MERGE_BRIDGE_OFFLINE = "(() => { const real = window.fetch.bind(window); window.fetch = (url, ...rest) => String(url).includes('127.0.0.1:9292') ? Promise.reject(new TypeError('Failed to fetch')) : real(url, ...rest); })();";

/**
 * The scanner box shows what the real bridge says, so a page that has one on load gets it hidden before the shot (hidden, not removed: React still owns the node).
 */
const MERGE_HIDE_SCANNER = "document.querySelectorAll('div.rounded-lg').forEach((box) => { if (box.textContent.startsWith('MyKad Smart Card Reader')) { box.style.display = 'none'; } });";

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    $actor = auth()->user();

    $this->survivor = Guest::factory()->create([
        'property_id' => $this->property->id,
        'full_name' => 'Aisyah binti Rahman',
        'email' => 'aisyah.rahman@example.my',
        'phone' => '+60123456789',
    ]);
    app(AddIdentityDocument::class)($actor, $this->survivor, new IdentityDocumentInputData(IdentityDocumentType::MyKad, '900101141234', 'MY', '2031-03-15'));

    $this->duplicate = Guest::factory()->create([
        'property_id' => $this->property->id,
        'full_name' => 'Rahman, Aisyah',
        'email' => null,
        'phone' => '+60123456789',
        'date_of_birth' => '1990-01-01',
        'is_vip' => true,
    ]);
    GuestNote::factory()->create(['guest_id' => $this->duplicate->id, 'author_id' => $actor->id, 'body' => 'Prefers a quiet room.']);
    app(AddIdentityDocument::class)($actor, $this->duplicate, new IdentityDocumentInputData(IdentityDocumentType::Passport, 'A12345678', 'MY', '2029-08-20'));
});

test('guest form warning', function () {
    $page = $this->open(route('guests.index', $this->property));
    $page->script(MERGE_BRIDGE_OFFLINE);
    $page->click('Add guest')
        ->waitForText('Add guest')
        ->type('#add-full_name', 'Aisyah Rahman')
        ->type('#add-phone', '0123456789')
        ->type('#add-email', 'aisyah.rahman@example.my')
        ->wait(0.5)
        ->keys('#add-email', 'Tab')
        ->waitForText('Possible duplicate guest')
        ->hover('[role="dialog"] >> text=Full name');

    $this->shoot($page, 'merge-guests-guest-form-warning', frame: '[role="dialog"]');
});

test('id form warning', function () {
    $other = Guest::factory()->create(['property_id' => $this->property->id, 'full_name' => 'Hafiz Ismail']);

    $page = $this->open(route('guests.documents.index', [$this->property, $other]))
        ->waitForText('No ID documents yet');
    $page->script(MERGE_BRIDGE_OFFLINE);
    $page->click('Add document')
        ->waitForText('Add ID document')
        ->type('#document-number', '900101-14-1234')
        ->wait(0.5)
        ->keys('#document-number', 'Tab')
        ->waitForText('Possible duplicate guest');

    $this->shoot($page, 'merge-guests-id-form-warning', frame: '[role="dialog"]');
});

test('booking form warning', function () {
    $page = $this->open(route('reservations.walk-in', $this->property), height: 1200);
    $page->script(MERGE_BRIDGE_OFFLINE);
    $page->script(MERGE_HIDE_SCANNER);
    $page->type('#guest-0-full_name', 'Aisyah Rahman')
        ->type('#guest-0-email', 'aisyah.rahman@example.my')
        ->wait(0.5)
        ->keys('#guest-0-email', 'Tab')
        ->waitForText('Possible duplicate guest');
    $page->script(MERGE_HIDE_SCANNER);
    $page->script("document.querySelector('[data-slot=\"alert\"]').scrollIntoView({ block: 'center' });");

    $this->shoot($page, 'merge-guests-booking-form-warning', badges: ['Use existing', 'Create new anyway'], frame: '[data-slot="alert"]');
});

test('possible duplicates list', function () {
    $page = $this->open(route('guests.show', [$this->property, $this->survivor]), height: 1200)
        ->waitForText('Possible duplicates');
    $page->script(MERGE_HIDE_SCANNER);

    $this->shoot($page, 'merge-guests-possible-duplicates', badges: ['Merge']);
});

test('merge dialog', function () {
    $page = $this->open(route('guests.show', [$this->property, $this->survivor]), height: 1200)
        ->waitForText('Possible duplicates')
        ->click('Merge')
        ->waitForText('This cannot be undone.');

    $this->shoot($page, 'merge-guests-dialog', frame: '[role="alertdialog"]');
});

test('after the merge', function () {
    $page = $this->open(route('guests.show', [$this->property, $this->survivor]), height: 1200)
        ->waitForText('Possible duplicates')
        ->click('Merge')
        ->click('Merge guests')
        ->waitForText('merged into');
    $page->script(MERGE_HIDE_SCANNER);

    $this->shoot($page, 'merge-guests-after');
});
