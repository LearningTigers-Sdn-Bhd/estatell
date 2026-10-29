<?php

use App\Enums\IdentityDocumentType;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;

beforeEach(function () {
    $this->signInAsScenariosFinance();
});

/**
 * Give the guests of the property a gender, a date of birth and an ID document, which the scenarios seeder leaves
 * blank: a MyKad for a Malaysian, a Passport for a foreign guest, and for one foreign guest a MyKad newer than the
 * Passport, so the report shows the Passport first.
 */
$addIdentities = function () {
    $guests = Guest::query()->where('property_id', $this->property->id)->whereNotNull('nationality_code')->orderBy('id')->get();

    foreach ($guests as $index => $guest) {
        $guest->forceFill([
            'gender' => $index % 2 === 0 ? 'female' : 'male',
            'date_of_birth' => (1975 + ($index * 3) % 25).'-0'.(1 + $index % 9).'-1'.($index % 9),
        ])->save();

        $isMalaysian = $guest->nationality_code === 'MY';

        GuestIdentityDocument::factory()->create([
            'guest_id' => $guest->id,
            'type' => $isMalaysian ? IdentityDocumentType::MyKad : IdentityDocumentType::Passport,
            'number' => $isMalaysian ? '8503121256'.str_pad((string) (10 + $index), 2, '0', STR_PAD_LEFT) : 'K'.str_pad((string) (4820000 + $index * 137), 7, '0', STR_PAD_LEFT).'7',
            'issuing_country_code' => $guest->nationality_code,
        ]);
    }

    $foreign = $guests->first(fn (Guest $guest): bool => $guest->nationality_code !== 'MY');

    GuestIdentityDocument::factory()->create([
        'guest_id' => $foreign->id,
        'type' => IdentityDocumentType::Other,
        'number' => 'DL7731905',
        'issuing_country_code' => $foreign->nationality_code,
    ]);
};

test('police report', function () use ($addIdentities) {
    $addIdentities->call($this);

    $page = $this->open(route('reports.police-report', [$this->property, 'from' => '2026-10-12', 'to' => '2026-10-14']))
        ->waitForText('Police report');

    $this->shoot($page, 'police-report', badges: ['Nationality', 'Export'], highlight: ['label:has([role="checkbox"])', 'thead th:nth-child(8)']);
});

test('police report foreign only', function () use ($addIdentities) {
    $addIdentities->call($this);

    $page = $this->open(route('reports.police-report', [$this->property, 'from' => '2026-10-12', 'to' => '2026-10-14', 'foreign' => 1]))
        ->waitForText('Police report');

    $this->shoot($page, 'police-report-foreign-only', highlight: ['label:has([role="checkbox"])']);
});
