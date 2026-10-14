<?php

use App\Actions\Reservations\GroupUpdateResult;
use App\Actions\Reservations\UpdateReservationGroup;
use App\Enums\BillingMode;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('it changes the group name and billing mode and logs the change', function () {
    $reservation = Reservation::factory()->create();
    $actor = User::factory()->for($reservation->property)->create();

    $updated = app(UpdateReservationGroup::class)($actor, $reservation, '  Wedding party ', BillingMode::EachPays);

    $activity = Activity::query()->sole();

    expect($updated->reservation->group_name)->toBe('Wedding party')
        ->and($reservation->fresh()->group_name)->toBe('Wedding party')
        ->and($reservation->fresh()->billing_mode)->toBe(BillingMode::EachPays)
        ->and($activity->event)->toBe('reservation.group_updated')
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->properties['old']['billing_mode'])->toBe('master_pays')
        ->and($activity->properties['new']['billing_mode'])->toBe('each_pays');
});

test('a blank group name clears it', function (?string $name) {
    $reservation = Reservation::factory()->group()->create();
    $actor = User::factory()->for($reservation->property)->create();

    app(UpdateReservationGroup::class)($actor, $reservation, $name, BillingMode::MasterPays);

    expect($reservation->fresh()->group_name)->toBeNull();
})->with([null, '', '   ']);

test('nothing is written or logged when nothing changed', function () {
    $reservation = Reservation::factory()->group('Tour')->create();
    $actor = User::factory()->for($reservation->property)->create();
    $updatedAt = $reservation->fresh()->updated_at;

    $this->travel(5)->minutes();
    app(UpdateReservationGroup::class)($actor, $reservation, 'Tour', BillingMode::MasterPays);

    expect(Activity::query()->count())->toBe(0)
        ->and($reservation->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('another reservation is left alone', function () {
    $reservation = Reservation::factory()->create();
    $other = Reservation::factory()->group('Keep')->create();
    $actor = User::factory()->for($reservation->property)->create();

    app(UpdateReservationGroup::class)($actor, $reservation, 'Changed', BillingMode::EachPays);

    expect($other->fresh()->group_name)->toBe('Keep')
        ->and($other->fresh()->billing_mode)->toBe(BillingMode::MasterPays);
});

/**
 * A Completed payment of the amount on the folio, or a refund of `$refundOf`, each with its ledger line.
 */
function groupMoney(Folio $folio, string $amount, ?Payment $refundOf = null): Payment
{
    $line = FolioLine::factory()->payment($amount)->create(['folio_id' => $folio->id]);

    return Payment::factory()->create([
        'folio_id' => $folio->id,
        'amount' => $amount,
        'refund_of_id' => $refundOf?->id,
        'receipt_number' => $refundOf === null ? 'R-'.fake()->unique()->numerify('######') : null,
        'folio_line_id' => $line->id,
    ]);
}

function groupMaster(Reservation $reservation): Folio
{
    return Folio::factory()->master()->create(['property_id' => $reservation->property_id, 'reservation_id' => $reservation->id]);
}

test('a billing mode change is refused while the master holds money, and it writes and logs nothing', function () {
    $reservation = Reservation::factory()->create();
    $actor = User::factory()->for($reservation->property)->create();
    $master = groupMaster($reservation);
    groupMoney($master, '100.00');

    $result = app(UpdateReservationGroup::class)($actor, $reservation, 'Renamed', BillingMode::MasterPaysAll);

    expect($result->refusal)->toBe(GroupUpdateResult::REFUSAL_MASTER_HAS_PAYMENTS)
        ->and($result->amount)->toBe('100.00')
        ->and($reservation->fresh()->billing_mode)->toBe(BillingMode::MasterPays)
        ->and($reservation->fresh()->group_name)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('a name-only edit and a fully refunded master do not block', function () {
    $reservation = Reservation::factory()->create();
    $actor = User::factory()->for($reservation->property)->create();
    $master = groupMaster($reservation);
    $payment = groupMoney($master, '100.00');

    expect(app(UpdateReservationGroup::class)($actor, $reservation, 'Tour', BillingMode::MasterPays)->isRefused())->toBeFalse();

    groupMoney($master, '100.00', $payment);

    expect(app(UpdateReservationGroup::class)($actor, $reservation, 'Tour', BillingMode::EachPays)->isRefused())->toBeFalse()
        ->and($reservation->fresh()->billing_mode)->toBe(BillingMode::EachPays);
});

test('the group route shows the refusal on the billing mode field and saves otherwise', function () {
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $reservation = Reservation::factory()->create(['property_id' => $user->property_id]);
    $currency = PropertySetting::factory()->for($user->property)->create()->refresh()->currency->getCurrencyCode();
    $url = route('reservations.group.update', ['property' => $user->property->code, 'reservation' => $reservation->id]);
    $master = groupMaster($reservation);
    groupMoney($master, '1000.00');

    $this->actingAs($user)->put($url, ['group_name' => 'Tour', 'billing_mode' => 'each_pays'])
        ->assertSessionHasErrors(['billing_mode' => 'The Master folio holds '.$currency.' 1,000.00 in payments. Refund it before changing the billing mode.']);

    expect($reservation->fresh()->billing_mode)->toBe(BillingMode::MasterPays)
        ->and(Folio::query()->where('type', FolioType::Master)->count())->toBe(1);

    $this->put($url, ['group_name' => 'Tour', 'billing_mode' => 'master_pays'])->assertSessionHasNoErrors();

    expect($reservation->fresh()->group_name)->toBe('Tour');
});
