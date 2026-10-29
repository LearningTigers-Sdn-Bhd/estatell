<?php

use App\Models\Guest;
use App\Models\GuestIdentityDocument;

beforeEach(function () {
    $this->signInAsScenariosFinance();
});

test('non-national guests report', function () {
    $passports = ['Kang Dahye' => 'M48213907', "Liam O'Connor" => 'PA7740216', 'Joanne Koh' => 'K3318452', 'Lea Hoffmann' => 'C01X6TR9P'];

    foreach ($passports as $name => $number) {
        $guest = Guest::query()->where('property_id', $this->property->id)->where('full_name', $name)->firstOrFail();

        GuestIdentityDocument::factory()->passport()->create(['guest_id' => $guest->id, 'number' => $number]);
    }

    $page = $this->open(route('reports.non-national-guests', ['property' => $this->property, 'from' => '2026-10-08', 'to' => '2026-10-14']))
        ->wait(1);

    $this->shoot($page, 'non-national-guests',
        badges: ['Custom dates', 'Nationality', 'ID', 'Tourism tax'],
    );
});
