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

test('vip guest page', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Ahmad Razak')]))
        ->waitForText('Personal details');

    $this->shoot($page, 'vip-and-blacklist-vip-page', badges: ['Remove VIP', 'Blacklist']);
});

test('guest page buttons for a normal guest', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Tan Mei Ling')]))
        ->waitForText('Personal details');

    $this->shoot($page, 'vip-and-blacklist-mark-vip', badges: ['Mark as VIP', 'Blacklist']);
});

test('blacklist dialog', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Tan Mei Ling')]))
        ->click('Blacklist')
        ->waitForText('Blacklist Tan Mei Ling')
        ->type('#blacklist-reason', 'Damaged the room and refused to pay.');

    $this->shoot($page, 'vip-and-blacklist-dialog', frame: '[role="dialog"]');
});

test('blacklisted guest page', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Wang Jun')]))
        ->waitForText('Left without paying the minibar bill.');

    $this->shoot($page, 'vip-and-blacklist-blacklisted-page', badges: ['Remove from blacklist']);
});

test('remove from blacklist dialog', function () {
    $page = $this->open(route('guests.show', [$this->property, ($this->guest)('Wang Jun')]))
        ->click('Remove from blacklist')
        ->waitForText('Remove Wang Jun from the blacklist?');

    $this->shoot($page, 'vip-and-blacklist-remove-dialog', frame: '[role="alertdialog"]');
});
