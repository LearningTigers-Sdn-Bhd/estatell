<?php

require_once __DIR__.'/../Support/WalkInSetup.php';

use App\Actions\FrontDesk\CheckInAndCollect;
use App\Data\Reservations\CollectNowData;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $setup
 * @param  list<string>  $permissions
 */
function walkInStaff(array $setup, array $permissions): User
{
    return User::factory()->for($setup['property'])->withPermissions($permissions)->create();
}

/** @return list<string> */
function walkInAllPermissions(): array
{
    return ['reservations.manage', 'reservations.view', 'front-desk.check-in', 'payment.record', 'guests.manage'];
}

test('a walk-in books the stay, checks every unit in and records the bill and the deposit the preview showed', function () {
    $setup = walkInCheckInSetup();
    $body = walkInSaveBody($setup);

    $preview = $this->actingAs($setup['user'])->postJson(route('reservations.walk-in-payment', ['property' => $setup['property']->code]), $body)->assertOk();

    expect($preview->json('totalWithRoomCharges'))->toBe($body['expected_total']);

    $response = $this->post(walkInSaveUrl($setup), $body)->assertSessionHasNoErrors();

    $reservation = Reservation::query()->sole();
    $payments = Payment::query()->orderBy('id')->get();

    expect($reservation->status)->toBe(ReservationStatus::InHouse)
        ->and($reservation->units()->pluck('status')->unique()->all())->toBe([UnitStatus::CheckedIn])
        ->and($payments->pluck('purpose')->all())->toBe([PaymentPurpose::Advance, PaymentPurpose::SecurityDeposit, PaymentPurpose::Advance, PaymentPurpose::SecurityDeposit])
        ->and($payments->pluck('amount')->all())->toBe(['216.00', '100.00', '216.00', '100.00'])
        ->and($payments->sum(fn (Payment $payment): float => (float) $payment->amount))->toBe((float) $preview->json('totalWithRoomCharges'))
        ->and($payments->pluck('folio.type')->unique()->all())->toBe([FolioType::Unit]);

    $response->assertRedirect(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $reservation->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "Reservation {$reservation->reference}: 2 of 2 checked in · MYR 632.00 collected"]);

    $log = Activity::query()->where('event', 'reservation.walk_in')->sole();

    expect($log->properties->all())->toMatchArray(['units' => 2, 'checked_in' => 2, 'collected' => '632.00'])
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(2);
});

test('with Room charges unticked the walk-in collects the Collect at check-in taxes and the deposit', function () {
    $setup = walkInCheckInSetup();

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['collect_room_charges' => false, 'expected_total' => '232.00']))->assertSessionHasNoErrors();

    expect(Payment::query()->orderBy('id')->pluck('amount')->all())->toBe(['16.00', '100.00', '16.00', '100.00']);
});

test('a walk-in with nothing ticked checks in and takes no payment', function () {
    $setup = walkInCheckInSetup();

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['collect_room_charges' => false, 'collect_deposit' => false, 'expected_total' => '0.00']))->assertSessionHasNoErrors();

    expect(Reservation::query()->sole()->status)->toBe(ReservationStatus::InHouse)
        ->and(Payment::query()->count())->toBe(0);
});

test('a refused booking saves nothing and shows the errors of a booking', function () {
    $setup = walkInCheckInSetup();
    $body = walkInSaveBody($setup);
    $body['lines'][1]['room_id'] = $setup['rooms'][0]->id;

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), $body)->assertSessionHasErrors();

    expect(Reservation::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Folio::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('a total that is not the one the rail showed saves nothing and answers amount_changed', function () {
    $setup = walkInCheckInSetup();
    $guests = Guest::query()->count();

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['expected_total' => '631.99']))->assertSessionHasErrors('amount_changed');

    expect(Reservation::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Folio::query()->count())->toBe(0)
        ->and(Guest::query()->count())->toBe($guests)
        ->and(Activity::query()->count())->toBe(0);
});

test('a unit refused for its card stays Confirmed and pays nothing while the other units check in and pay', function () {
    $setup = walkInCheckInSetup(['card_required_for_check_in' => true]);
    $body = walkInSaveBody($setup, [
        'guests' => [['guest_id' => $setup['guest']->id], ['full_name' => 'Lim Wei Jie', 'nationality_code' => 'MY', 'stays_in' => 0]],
    ]);

    $response = $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), $body)->assertSessionHasNoErrors();

    $reservation = Reservation::query()->sole();
    [$refused, $done] = $reservation->units()->orderBy('id')->get()->all();

    expect($refused->status)->toBe(UnitStatus::Confirmed)
        ->and($done->status)->toBe(UnitStatus::CheckedIn)
        ->and($reservation->status)->toBe(ReservationStatus::InHouse)
        ->and(Payment::query()->orderBy('id')->pluck('amount')->all())->toBe(['216.00', '100.00'])
        ->and(Payment::query()->with('folio')->get()->pluck('folio.reservation_unit_id')->unique()->all())->toBe([$done->id])
        ->and(Activity::query()->where('event', 'reservation.walk_in')->sole()->properties->all())->toMatchArray(['units' => 2, 'checked_in' => 1, 'collected' => '316.00']);

    $room = $refused->nights()->first()->room->number;

    $response->assertInertiaFlash('toast', [
        'type' => 'warning',
        'message' => "Reservation {$reservation->reference}: 1 of 2 checked in · MYR 316.00 collected",
        'description' => "Room {$room}: A registration card is not signed. Sign it, or tick Check in without a card.",
    ]);
});

test('without_card is ignored for a user without front-desk.check-in-without-card', function () {
    $setup = walkInCheckInSetup(['card_required_for_check_in' => true]);
    $body = walkInSaveBody($setup, ['guests' => [['guest_id' => $setup['guest']->id, 'stays_in' => 0]], 'without_card' => true, 'collect_room_charges' => false, 'collect_deposit' => false]);

    $this->actingAs(walkInStaff($setup, walkInAllPermissions()))->post(walkInSaveUrl($setup), $body)->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->where('status', UnitStatus::Confirmed)->count())->toBe(1)
        ->and(ReservationUnit::query()->where('status', UnitStatus::CheckedIn)->count())->toBe(1);
});

test('with front-desk.check-in-without-card, without_card checks in a unit that has no signed card', function () {
    $setup = walkInCheckInSetup(['card_required_for_check_in' => true]);
    $body = walkInSaveBody($setup, ['guests' => [['guest_id' => $setup['guest']->id, 'stays_in' => 0]], 'without_card' => true, 'collect_room_charges' => false, 'collect_deposit' => false]);

    $this->actingAs(walkInStaff($setup, [...walkInAllPermissions(), 'front-desk.check-in-without-card']))->post(walkInSaveUrl($setup), $body)->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->where('status', UnitStatus::CheckedIn)->count())->toBe(2);
});

test('a payment refusal keeps every unit Confirmed with the reservation saved and names each reason', function () {
    $setup = walkInCheckInSetup();

    $response = $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['payment_method_id' => 999999]))->assertSessionHasNoErrors();

    $reservation = Reservation::query()->sole();

    expect($reservation->units()->pluck('status')->unique()->all())->toBe([UnitStatus::Confirmed])
        ->and(Payment::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(0);

    $response->assertInertiaFlash('toast', [
        'type' => 'warning',
        'message' => "Reservation {$reservation->reference}: 0 of 2 checked in",
        'description' => "Room 101: This payment method is not available.\nRoom 102: This payment method is not available.",
    ]);
});

test('a unit whose payment is refused rolls back alone inside the walk-in transaction', function () {
    $setup = walkInCheckInSetup();
    $this->actingAs($setup['user'])->post(route('reservations.store', ['property' => $setup['property']->code]), walkInSaveBody($setup, ['booking_source_id' => $setup['phone']->id]))->assertSessionHasNoErrors();
    [$first, $second] = Reservation::query()->sole()->units()->orderBy('id')->get()->all();
    $collect = fn (int $method, string $key): CollectNowData => new CollectNowData(taxes: true, deposit: true, paymentMethodId: $method, reference: null, idempotencyKey: $key, roomCharges: true);

    DB::transaction(function () use ($setup, $first, $second, $collect): void {
        $refused = app(CheckInAndCollect::class)($setup['user'], $first, collect: $collect(999999, 'refused:1'));
        $paid = app(CheckInAndCollect::class)($setup['user'], $second, collect: $collect($setup['cash']->id, 'paid:2'));

        expect($refused->paymentRefusal)->toBe('method_not_available')
            ->and($paid->paymentRefusal)->toBeNull();
    });

    expect($first->fresh()->status)->toBe(UnitStatus::Confirmed)
        ->and($second->fresh()->status)->toBe(UnitStatus::CheckedIn)
        ->and(Payment::query()->with('folio')->get()->pluck('folio.reservation_unit_id')->unique()->all())->toBe([$second->id])
        ->and(Payment::query()->orderBy('id')->pluck('amount')->all())->toBe(['216.00', '100.00'])
        ->and(Activity::query()->where('event', 'reservation.unit_checked_in')->count())->toBe(1);
});

test('a group where Master pays and has no company records the bill on the Master folio and the deposit on the unit folios', function () {
    $setup = walkInCheckInSetup();

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['billing_mode' => 'master_pays']))->assertSessionHasNoErrors();

    $bills = Payment::query()->where('purpose', PaymentPurpose::Advance)->with('folio')->get();
    $deposits = Payment::query()->where('purpose', PaymentPurpose::SecurityDeposit)->with('folio')->get();

    expect(ReservationUnit::query()->where('status', UnitStatus::CheckedIn)->count())->toBe(2)
        ->and($bills->pluck('amount')->all())->toBe(['216.00', '216.00'])
        ->and($bills->pluck('folio.type')->unique()->all())->toBe([FolioType::Master])
        ->and($deposits->pluck('folio.type')->unique()->all())->toBe([FolioType::Unit])
        ->and($deposits->pluck('amount')->all())->toBe(['100.00', '100.00']);
});

test('a company billed on Master pays collects no bill and still takes the deposit', function () {
    $setup = walkInCheckInSetup();
    $company = Company::factory()->for($setup['property'])->create();

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['company_id' => $company->id, 'billing_mode' => 'master_pays', 'expected_total' => '200.00']))->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->where('status', UnitStatus::CheckedIn)->count())->toBe(2)
        ->and(Payment::query()->pluck('purpose')->unique()->all())->toBe([PaymentPurpose::SecurityDeposit])
        ->and(Payment::query()->orderBy('id')->pluck('amount')->all())->toBe(['100.00', '100.00']);
});

test('the payment fields are ignored for a user without payment.record', function () {
    $setup = walkInCheckInSetup();
    $user = walkInStaff($setup, ['reservations.manage', 'reservations.view', 'front-desk.check-in', 'guests.manage']);

    $this->actingAs($user)->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['expected_total' => '1.00', 'payment_method_id' => 999999]))->assertSessionHasNoErrors();

    expect(ReservationUnit::query()->where('status', UnitStatus::CheckedIn)->count())->toBe(2)
        ->and(Payment::query()->count())->toBe(0);
});

test('a method that needs a reference refuses a walk-in without one before anything is saved', function () {
    $setup = walkInCheckInSetup();
    $card = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'requires_reference' => true, 'position' => 2]);
    $body = walkInSaveBody($setup, ['payment_method_id' => $card->id]);

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), $body)->assertSessionHasErrors('reference');

    expect(Reservation::query()->count())->toBe(0);

    $this->post(walkInSaveUrl($setup), [...$body, 'reference' => 'TXN-1'])->assertSessionHasNoErrors();

    expect(Payment::query()->count())->toBe(4);
});

test('the source must be the Walk-in type', function () {
    $setup = walkInCheckInSetup();

    $this->actingAs($setup['user'])->post(walkInSaveUrl($setup), walkInSaveBody($setup, ['booking_source_id' => $setup['phone']->id]))->assertSessionHasErrors('booking_source_id');

    expect(Reservation::query()->count())->toBe(0);
});

test('saving a walk-in needs front-desk.check-in and reservations.manage and stays in its property', function () {
    $setup = walkInCheckInSetup();

    $this->actingAs(walkInStaff($setup, ['reservations.manage', 'payment.record']))->post(walkInSaveUrl($setup), walkInSaveBody($setup))->assertForbidden();
    $this->actingAs(walkInStaff($setup, ['front-desk.check-in', 'payment.record']))->post(walkInSaveUrl($setup), walkInSaveBody($setup))->assertForbidden();

    $other = walkInCheckInSetup();
    $this->actingAs($setup['user'])->post(walkInSaveUrl($other), walkInSaveBody($other))->assertNotFound();

    expect(Reservation::query()->count())->toBe(0);
});

test('the walk-in form offers the card box only when a card is required and the user may check in without one', function () {
    $setup = walkInCheckInSetup(['card_required_for_check_in' => true]);
    $url = route('reservations.walk-in', ['property' => $setup['property']->code]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn ($page) => $page->where('canCheckInWithoutCard', true));
    $this->actingAs(walkInStaff($setup, walkInAllPermissions()))->get($url)->assertInertia(fn ($page) => $page->where('canCheckInWithoutCard', false));

    $setup['property']->settings->update(['card_required_for_check_in' => false]);
    $this->actingAs($setup['user'])->get($url)->assertInertia(fn ($page) => $page->where('canCheckInWithoutCard', false));
});
