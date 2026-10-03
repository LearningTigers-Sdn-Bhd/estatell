<?php

use App\Actions\Reservations\UpdateReservationGroup;
use App\Enums\BillingMode;
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
