<?php

use App\Actions\Folio\BillToGuest;
use App\Actions\Folio\BillToGuestResult;
use App\Actions\Reservations\AttachGuest;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property, an owner and a reservation with one checked-in unit that has a primary guest and a second guest.
 *
 * @return array{user: User, reservation: Reservation, unit: ReservationUnit, primary: Guest, other: Guest}
 */
function btgSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
    $primary = Guest::factory()->create(['property_id' => $property->id]);
    $other = Guest::factory()->create(['property_id' => $property->id]);
    app(AttachGuest::class)($user, $unit, $primary);
    app(AttachGuest::class)($user, $unit, $other);

    return compact('user', 'reservation', 'unit', 'primary', 'other');
}

function btgUrl(array $setup): string
{
    return route('reservations.units.bill-to-guest', ['property' => $setup['user']->property->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id]);
}

test('billing the primary guest creates the unit folio with that guest as payer', function () {
    $setup = btgSetup();

    $result = app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['primary']);

    expect($result->isRefused())->toBeFalse()
        ->and($result->created)->toBeTrue()
        ->and($result->folio->type)->toBe(FolioType::Unit)
        ->and($result->folio->payer_guest_id)->toBe($setup['primary']->id)
        ->and(Activity::query()->where('event', 'folio.payer_changed')->count())->toBe(1);
});

test('billing the primary guest changes the payer of an Open unit folio and keeps the lines', function () {
    $setup = btgSetup();
    $folio = Folio::factory()->create([
        'property_id' => $setup['reservation']->property_id,
        'reservation_id' => $setup['reservation']->id,
        'reservation_unit_id' => $setup['unit']->id,
        'type' => FolioType::Unit,
        'payer_guest_id' => $setup['reservation']->booker_guest_id,
    ]);

    $result = app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['primary']);

    expect($result->folio->id)->toBe($folio->id)
        ->and($result->created)->toBeFalse()
        ->and($folio->refresh()->payer_guest_id)->toBe($setup['primary']->id)
        ->and(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->count())->toBe(1);

    expect(app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['primary'])->refusal)->toBe(BillToGuestResult::REFUSAL_ALREADY_BILLED);
});

test('a closed unit folio refuses a payer change and logs nothing', function () {
    $setup = btgSetup();
    $folio = Folio::factory()->create([
        'property_id' => $setup['reservation']->property_id,
        'reservation_id' => $setup['reservation']->id,
        'reservation_unit_id' => $setup['unit']->id,
        'type' => FolioType::Unit,
        'status' => FolioStatus::Closed,
        'payer_guest_id' => $setup['reservation']->booker_guest_id,
    ]);

    $result = app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['primary']);

    expect($result->refusal)->toBe(BillToGuestResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and($folio->refresh()->payer_guest_id)->toBe($setup['reservation']->booker_guest_id)
        ->and(Activity::query()->where('event', 'folio.payer_changed')->count())->toBe(0);
});

test('billing another guest makes a third-party folio once and reuses it', function () {
    $setup = btgSetup();

    $first = app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['other']);
    $second = app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['other']);

    expect($first->folio->type)->toBe(FolioType::ThirdParty)
        ->and($first->folio->payer_guest_id)->toBe($setup['other']->id)
        ->and($first->created)->toBeTrue()
        ->and($second->folio->id)->toBe($first->folio->id)
        ->and($second->created)->toBeFalse()
        ->and(Folio::query()->where('type', FolioType::ThirdParty)->count())->toBe(1);
});

test('a guest who is not on the unit is refused', function () {
    $setup = btgSetup();
    $stranger = Guest::factory()->create(['property_id' => $setup['user']->property_id]);

    expect(app(BillToGuest::class)($setup['user'], $setup['unit'], $stranger)->refusal)->toBe(BillToGuestResult::REFUSAL_GUEST_NOT_ON_UNIT)
        ->and(Folio::query()->count())->toBe(0);
});

test('the route bills the guest and toasts, needs folio.transfer and stays inside the property', function () {
    $setup = btgSetup();

    $this->actingAs($setup['user'])
        ->from('/back')
        ->post(btgUrl($setup), ['guest_id' => $setup['other']->id])
        ->assertRedirect('/back')
        ->assertInertiaFlash('toast.type', 'success');

    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['user']->property_id]);
    $this->actingAs($frontDesk)->post(btgUrl($setup), ['guest_id' => $setup['primary']->id])->assertForbidden();

    $foreign = Guest::factory()->create();
    $this->actingAs($setup['user'])->post(btgUrl($setup), ['guest_id' => $foreign->id])->assertNotFound();

    expect(Folio::query()->where('payer_guest_id', $setup['primary']->id)->count())->toBe(0);
});

test('the guests tab marks who can be billed and who already pays', function () {
    $setup = btgSetup();
    $url = route('reservations.guests', ['property' => $setup['user']->property->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('guests', fn ($rows) => collect($rows)->whereNotNull('unitId')->every(fn ($row) => $row['canBill'] === true && $row['billing'] === null)));

    app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['primary']);
    app(BillToGuest::class)($setup['user'], $setup['unit'], $setup['other']);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('guests', fn ($rows) => collect($rows)->whereNotNull('unitId')->pluck('billing', 'guestId')->all() === [
            $setup['primary']->id => 'Pays unit folio',
            $setup['other']->id => 'Own folio',
        ] && collect($rows)->every(fn ($row) => $row['canBill'] === false)));
});
