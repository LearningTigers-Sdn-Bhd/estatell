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

test('search', function () {
    $page = $this->open(route('guests.index', [$this->property, 'filter' => ['search' => 'tan']]))
        ->waitForText('Tan Mei Ling');

    $this->shoot($page, 'guest-list-search', badges: ['Name', 'Added']);
});

test('status filter menu', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak')
        ->click('[aria-label="Guest status"]')
        ->waitForText('Archived');

    $this->shoot($page, 'guest-list-status-menu');
});

test('blacklisted filter', function () {
    $page = $this->open(route('guests.index', [$this->property, 'filter' => ['status' => 'blacklisted']]))
        ->waitForText('Wang Jun');

    $this->shoot($page, 'guest-list-blacklisted');
});

test('tag filter', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak')
        ->click('[aria-label="Tag"]')
        ->waitForText('Corporate');

    $this->shoot($page, 'guest-list-tag-menu');
});

test('selection bar', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak')
        ->click('tbody tr:nth-child(1) [aria-label="Select row"]')
        ->click('tbody tr:nth-child(2) [aria-label="Select row"]')
        ->waitForText('2 selected');

    $this->shoot($page, 'guest-list-selection-bar');
});

test('bulk blacklist menu', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak')
        ->click('tbody tr:nth-child(1) [aria-label="Select row"]')
        ->click('tbody tr:nth-child(2) [aria-label="Select row"]')
        ->waitForText('2 selected')
        ->click('[aria-label="Bulk actions"] button:has-text("Blacklist")')
        ->waitForText('Remove from blacklist');

    $this->shoot($page, 'guest-list-bulk-menu');
});

test('bulk blacklist dialog', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak')
        ->click('tbody tr:nth-child(1) [aria-label="Select row"]')
        ->click('tbody tr:nth-child(2) [aria-label="Select row"]')
        ->waitForText('2 selected')
        ->click('[aria-label="Bulk actions"] button:has-text("Blacklist")')
        ->click('[role="menuitem"]:text-is("Blacklist")')
        ->waitForText('Blacklist 2 guests?');

    $this->shoot($page, 'guest-list-bulk-blacklist-dialog', frame: '[role="dialog"]');
});

test('bulk archive dialog', function () {
    $page = $this->open(route('guests.index', $this->property))
        ->waitForText('Ahmad Razak')
        ->click('tbody tr:nth-child(1) [aria-label="Select row"]')
        ->click('tbody tr:nth-child(2) [aria-label="Select row"]')
        ->waitForText('2 selected')
        ->click('[aria-label="Bulk actions"] button:has-text("Archive")')
        ->waitForText('Archive 2 guests?');

    $this->shoot($page, 'guest-list-bulk-archive-dialog', frame: '[role="alertdialog"]');
});
