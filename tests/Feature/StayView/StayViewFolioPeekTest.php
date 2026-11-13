<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Models\User;

function peekReservation(User $owner): Reservation
{
    PropertySetting::factory()->for($owner->property)->create();
    BusinessDate::factory()->create(['property_id' => $owner->property_id, 'date' => '2026-10-14']);

    return Reservation::factory()->create(['property_id' => $owner->property_id]);
}

function peekLine(Folio $folio, string $amount): FolioLine
{
    return FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => $amount]);
}

test('the peek sends the room total, paid amount and balance', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);
    $unit = ReservationUnit::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id]);
    UnitNight::factory()->for($unit)->create(['date' => '2026-10-14', 'base_amount' => '180.00', 'amount' => '180.00']);
    $folio = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id]);
    peekLine($folio, '200.00');
    FolioLine::factory()->payment('120.00')->create(['folio_id' => $folio->id]);

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertOk()
        ->assertJsonPath('total', '180.00')
        ->assertJsonPath('paid', '120.00')
        ->assertJsonPath('billedToCompany', '0.00')
        ->assertJsonPath('balance', '80.00')
        ->assertJsonPath('depositHeld', null);
});

test('a balance below zero is flagged as a deposit held', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);
    $folio = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id]);
    FolioLine::factory()->payment('300.00')->create(['folio_id' => $folio->id]);

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertJsonPath('balance', '-300.00')
        ->assertJsonPath('depositHeld.label', 'Deposit held');
});

test('voided folios and other reservations are left out', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);
    $folio = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id]);
    $voided = Folio::factory()->create(['property_id' => $owner->property_id, 'reservation_id' => $reservation->id, 'status' => FolioStatus::Voided, 'type' => 'third_party']);
    $other = Folio::factory()->create(['property_id' => $owner->property_id]);
    peekLine($folio, '50.00');
    peekLine($voided, '99.00');
    peekLine($other, '77.00');

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertJsonPath('balance', '50.00');
});

test('a reservation with no folio and no nights has zero figures', function () {
    $owner = User::factory()->owner()->create();
    $reservation = peekReservation($owner);

    $this->actingAs($owner)
        ->getJson(route('front-desk.stays.folio', [$owner->property->code, $reservation]))
        ->assertOk()
        ->assertExactJson(['total' => '0.00', 'paid' => '0.00', 'billedToCompany' => '0.00', 'balance' => '0.00', 'depositHeld' => null]);
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

test('the timeline tells the page whether the user may manage reservations', function () {
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();
    BusinessDate::factory()->create(['property_id' => $owner->property_id, 'date' => '2026-10-14']);
    $viewer = User::factory()->for($owner->property)->withPermissions(['front-desk.view', 'reservations.view'])->create();

    $this->actingAs($owner)->get(route('front-desk.timeline', $owner->property->code))
        ->assertInertia(fn ($page) => $page->where('canManageReservations', true));
    $this->actingAs($viewer)->get(route('front-desk.timeline', $owner->property->code))
        ->assertInertia(fn ($page) => $page->where('canManageReservations', false));
});

test('the timeline tells the page whether the user may override restrictions and waive fees', function () {
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();
    BusinessDate::factory()->create(['property_id' => $owner->property_id, 'date' => '2026-10-14']);
    $viewer = User::factory()->for($owner->property)->withPermissions(['front-desk.view', 'reservations.manage'])->create();

    $this->actingAs($owner)->get(route('front-desk.timeline', $owner->property->code))
        ->assertInertia(fn ($page) => $page->where('canOverrideRestrictions', true)->where('canWaiveFees', true));
    $this->actingAs($viewer)->get(route('front-desk.timeline', $owner->property->code))
        ->assertInertia(fn ($page) => $page->where('canOverrideRestrictions', false)->where('canWaiveFees', false));
});
