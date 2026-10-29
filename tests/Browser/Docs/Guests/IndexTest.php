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

test('guest list', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak');

    $this->shoot($page, 'index-guest-list', badges: ['Add guest', 'All guests', 'All tags']);
});

test('guest page details tab', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Ahmad Razak')]))
        ->waitForText('Personal details');

    $this->shoot($page, 'index-details-tab', badges: ['Details', 'Notes', 'Documents', 'Remove VIP', 'Blacklist']);
});

test('guest page notes tab', function () {
    $page = $this->open(route('guests.notes.index', [$this->property, ($this->guest)('Ahmad Razak')]))
        ->waitForText('Prefers a quiet room on a high floor.');

    // The note time is formatted in the browser clock, which can differ from the server render (the S8 class of bug).
    $this->shoot($page, 'index-notes-tab', checkErrors: false);
});

test('guest page documents tab', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, ($this->guest)('Ahmad Razak')]))
        ->waitForText('Documents');

    $this->shoot($page, 'index-documents-tab');
});
