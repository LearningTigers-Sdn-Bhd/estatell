<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostCharge;
use App\Data\Folio\NewChargeData;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date, an owner and a Unit folio that holds one 100.00 charge.
 *
 * @return array{user: User, property: Property, reservation: Reservation, folio: Folio}
 */
function fkSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    $folio = app(CreateFolio::class)($reservation, $unit);

    app(PostCharge::class)($user, $unit, NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $room->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '100.00',
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]));

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'folio' => $folio];
}

function fkSettle(Folio $folio): void
{
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '-100.00', 'unit_amount' => '-100.00', 'posting_date' => today()->toDateString()]);
}

function fkUrl(array $setup, string $route, ?Folio $folio = null): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => ($folio ?? $setup['folio'])->id]);
}

function fkPage(array $setup): string
{
    return route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);
}

test('closing a settled folio closes it, issues the invoice number and names the folio in the toast', function () {
    $setup = fkSetup();
    fkSettle($setup['folio']);

    $this->actingAs($setup['user'])
        ->post(fkUrl($setup, 'reservations.folios.close'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Folio '.$setup['folio']->number.' closed.']);

    $folio = $setup['folio']->refresh();

    expect($folio->status)->toBe(FolioStatus::Closed)
        ->and($folio->invoice_number)->not->toBeNull()
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('closing a folio with a balance or one that is already closed is an error toast and writes nothing', function () {
    $setup = fkSetup();

    $this->actingAs($setup['user'])
        ->post(fkUrl($setup, 'reservations.folios.close'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only a folio with a balance of 0.00 can be closed. Settle it or move the charges first.']);

    expect($setup['folio']->refresh()->status)->toBe(FolioStatus::Open);

    fkSettle($setup['folio']);
    $this->actingAs($setup['user'])->post(fkUrl($setup, 'reservations.folios.close'));
    $this->actingAs($setup['user'])
        ->post(fkUrl($setup, 'reservations.folios.close'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This folio is not open, so it cannot change.']);

    expect(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('reopening a closed folio opens it again and keeps the invoice number', function () {
    $setup = fkSetup();
    fkSettle($setup['folio']);
    app(CloseFolio::class)($setup['user'], $setup['folio']);
    $invoice = $setup['folio']->refresh()->invoice_number;

    $this->actingAs($setup['user'])
        ->post(fkUrl($setup, 'reservations.folios.reopen'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Folio '.$setup['folio']->number.' reopened.']);

    $folio = $setup['folio']->refresh();

    expect($folio->status)->toBe(FolioStatus::Open)
        ->and($folio->invoice_number)->toBe($invoice)
        ->and(Activity::query()->where('event', 'folio.reopened')->count())->toBe(1);

    $this->actingAs($setup['user'])
        ->post(fkUrl($setup, 'reservations.folios.reopen'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only a closed folio can be reopened.']);

    expect(Activity::query()->where('event', 'folio.reopened')->count())->toBe(1);
});

test('close and reopen need their permission and stay inside the reservation', function () {
    $setup = fkSetup();
    fkSettle($setup['folio']);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($housekeeping)->post(fkUrl($setup, 'reservations.folios.close'))->assertForbidden();
    $this->actingAs($frontDesk)->post(fkUrl($setup, 'reservations.folios.close'))->assertSessionHasNoErrors();
    $this->actingAs($frontDesk)->post(fkUrl($setup, 'reservations.folios.reopen'))->assertForbidden();

    expect($setup['folio']->refresh()->status)->toBe(FolioStatus::Closed);

    $foreign = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id]);

    $this->actingAs($setup['user'])->post(fkUrl($setup, 'reservations.folios.reopen', $foreign))->assertNotFound();
    $this->actingAs($setup['user'])->post(fkUrl($setup, 'reservations.folios.close', $foreign))->assertNotFound();
});

test('the page offers close on an open folio and reopen on a closed one, by permission', function () {
    $setup = fkSetup();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])->get(fkPage($setup))->assertInertia(fn (Assert $page) => $page
        ->where('folio.canClose', true)
        ->where('folio.canReopen', false));

    fkSettle($setup['folio']);
    app(CloseFolio::class)($setup['user'], $setup['folio']);

    $this->actingAs($setup['user'])->get(fkPage($setup))->assertInertia(fn (Assert $page) => $page
        ->where('folio.canClose', false)
        ->where('folio.canReopen', true)
        ->where('folio.invoiceNumber', fn ($number) => is_string($number)));

    $this->actingAs($frontDesk)->get(fkPage($setup))->assertInertia(fn (Assert $page) => $page
        ->where('folio.canClose', false)
        ->where('folio.canReopen', false));
});
