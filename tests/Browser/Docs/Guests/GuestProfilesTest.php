<?php

use App\Actions\Guests\AddGuestNote;
use App\Actions\Guests\BlacklistGuest;
use App\Actions\Guests\SyncGuestTags;
use App\Enums\DefaultRoleEnum;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\DemoSeeder;

beforeEach(function () {
    $this->signInAsDemoOwner('boreno', 'Boreno Hotel');

    $owner = User::query()->where('email', DemoSeeder::loginEmail('boreno', DefaultRoleEnum::Owner))->firstOrFail();
    $guest = fn (string $name): Guest => Guest::query()->where('property_id', $this->property->id)->where('full_name', $name)->firstOrFail();

    app(SyncGuestTags::class)($guest('Ahmad Razak'), ['Repeat guest', 'Corporate']);
    app(SyncGuestTags::class)($guest('Tan Mei Ling'), ['Corporate']);
    app(SyncGuestTags::class)($guest('Grace Lim'), ['Repeat guest']);
    app(BlacklistGuest::class)($owner, $guest('Wang Jun'), 'Left without paying the minibar bill.');
    app(AddGuestNote::class)($owner, $guest('Ahmad Razak'), 'Prefers a quiet room on a high floor.');
    app(AddGuestNote::class)($owner, $guest('Ahmad Razak'), 'Arrives late most Fridays. Keep the key at the desk.');

    $this->guest = $guest;
});

test('closed list with add guest button', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak');

    $this->shoot($page, 'guest-profiles-add-button', badges: ['Add guest']);
});

test('add guest sheet', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->click('Add guest')
        ->waitForText('Without a country code')
        ->type('#add-full_name', 'Nur Aisyah binti Rahman')
        ->type('#add-email', 'aisyah@example.com');

    $this->shoot($page, 'guest-profiles-add-sheet', frame: '[role="dialog"]');
});

test('details form', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Tan Mei Ling')]), 1300)
        ->waitForText('Personal details');

    $this->shoot($page, 'guest-profiles-details-form', badges: ['Contact', 'Personal details', 'Tags', 'Address']);
});

test('tags field', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Tan Mei Ling')]), 1300)
        ->waitForText('Personal details')
        ->click('#tags')
        ->type('#tags', 'Birthday');

    $this->shoot($page, 'guest-profiles-tags', element: 'fieldset:has(#tags)');
});

test('notes tab', function () {
    $page = $this->open(route('guests.notes.index', [$this->property, ($this->guest)('Ahmad Razak')]))
        ->waitForText('Prefers a quiet room on a high floor.');

    $this->shoot($page, 'guest-profiles-notes-tab', badges: ['Add note'], checkErrors: false);
});

test('add note dialog', function () {
    $page = $this->open(route('guests.notes.index', [$this->property, ($this->guest)('Ahmad Razak')]))
        ->click('Add note')
        ->waitForText('Add note for Ahmad Razak')
        ->type('#note-body', 'Allergic to feather pillows.');

    $this->shoot($page, 'guest-profiles-add-note-dialog', frame: '[role="dialog"]', checkErrors: false);
});
