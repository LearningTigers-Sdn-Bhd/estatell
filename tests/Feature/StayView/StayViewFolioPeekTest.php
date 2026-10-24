<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\User;
use App\Services\StayView\StayFolioPeek;

function peekReservation(User $owner): Reservation
{
    PropertySetting::factory()->for($owner->property)->create();
    BusinessDate::factory()->create(['property_id' => $owner->property_id, 'date' => '2026-10-14']);

    return Reservation::factory()->create(['property_id' => $owner->property_id]);
}

function peekLine(Folio $folio, string $date, string $amount, string $description = 'Room charge'): FolioLine
{
    return FolioLine::factory()->create(['folio_id' => $folio->id, 'posting_date' => $date, 'amount' => $amount, 'description' => $description]);
}

test('the peek lists the newest folio lines first with the balance', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);
    $folio = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id]);
    peekLine($folio, '2026-10-12', '200.00', 'Room night');
    peekLine($folio, '2026-10-14', '-120.00', 'Payment');
    peekLine($folio, '2026-10-13', '40.00', 'Minibar');

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertOk()
        ->assertJsonPath('balance', '120.00')
        ->assertJsonPath('hasMore', false)
        ->assertJsonPath('lines.0.description', 'Payment')
        ->assertJsonPath('lines.0.amount', '-120.00')
        ->assertJsonPath('lines.0.date', '2026-10-14')
        ->assertJsonPath('lines.1.description', 'Minibar')
        ->assertJsonPath('lines.2.description', 'Room night');
});

test('the peek lists the newest lines only and says when there are more', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);
    $folio = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id]);

    foreach (range(1, StayFolioPeek::LIMIT + 3) as $day) {
        peekLine($folio, '2026-09-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT), '10.00');
    }

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertJsonCount(StayFolioPeek::LIMIT, 'lines')
        ->assertJsonPath('hasMore', true)
        ->assertJsonPath('balance', '180.00');
});

test('lines of a voided folio and of other reservations are left out', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);
    $folio = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id]);
    $voided = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id, 'status' => FolioStatus::Voided, 'type' => 'third_party']);
    $other = Folio::factory()->create(['property_id' => $owner->property_id]);
    peekLine($folio, '2026-10-12', '50.00', 'Kept');
    peekLine($voided, '2026-10-13', '99.00', 'Voided folio');
    peekLine($other, '2026-10-13', '77.00', 'Other reservation');

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.description', 'Kept')
        ->assertJsonPath('balance', '50.00');
});

test('a reservation with no folio has no lines and a zero balance', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertOk()
        ->assertExactJson(['balance' => '0.00', 'lines' => [], 'hasMore' => false]);
});

test('the peek needs front-desk.view and reservations.view and stays inside the property', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);
    $url = route('front-desk.stays.folio', [$owner->property->code, $reservation]);
    $onlyTimeline = User::factory()->for($owner->property)->withPermissions(['front-desk.view'])->create();
    $onlyReservations = User::factory()->for($owner->property)->withPermissions(['reservations.view'])->create();
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();
    $theirs = Reservation::factory()->create(['property_id' => $finance->property_id]);

    $this->actingAs($onlyTimeline)->getJson($url)->assertForbidden();
    $this->actingAs($onlyReservations)->getJson($url)->assertForbidden();
    $this->actingAs($owner)->getJson(route('front-desk.stays.folio', [$owner->property->code, $theirs]))->assertNotFound();
});

test('the timeline tells the page whether the user may view reservations', function () {
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();
    BusinessDate::factory()->create(['property_id' => $owner->property_id, 'date' => '2026-10-14']);
    $limited = User::factory()->for($owner->property)->withPermissions(['front-desk.view'])->create();

    $this->actingAs($owner)->get(route('front-desk.timeline', $owner->property->code))
        ->assertInertia(fn ($page) => $page->where('canViewReservations', true));
    $this->actingAs($limited)->get(route('front-desk.timeline', $owner->property->code))
        ->assertInertia(fn ($page) => $page->where('canViewReservations', false));
});
