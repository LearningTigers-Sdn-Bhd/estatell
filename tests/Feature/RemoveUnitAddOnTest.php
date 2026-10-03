<?php

use App\Actions\Reservations\RemoveUnitAddOn;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function removeAddOn(User $actor, UnitAddOn $addOn): bool
{
    return app(RemoveUnitAddOn::class)($actor, $addOn);
}

test('it deletes one add-on and logs it on the reservation', function () {
    $unit = ReservationUnit::factory()->create();
    $keep = UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id]);
    $remove = UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'quantity' => 2, 'unit_amount' => '30.00', 'service_date' => '2026-11-03']);
    $actor = User::factory()->create();

    expect(removeAddOn($actor, $remove))->toBeTrue()
        ->and(UnitAddOn::query()->pluck('id')->all())->toBe([$keep->id]);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('reservation.add_on_removed')
        ->and($activity->subject_id)->toBe($unit->reservation_id)
        ->and($activity->subject_type)->toBe((new Reservation)->getMorphClass())
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->properties['charge_item_id'])->toBe($remove->charge_item_id)
        ->and($activity->properties['quantity'])->toBe(2)
        ->and($activity->properties['service_date'])->toBe('2026-11-03');
});

test('it returns false and logs nothing when the row is already gone', function () {
    $addOn = UnitAddOn::factory()->create();
    $copy = UnitAddOn::query()->findOrFail($addOn->id);
    $addOn->delete();

    expect(removeAddOn(User::factory()->create(), $copy))->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);
});

test('it works on a CheckedIn unit', function () {
    $addOn = UnitAddOn::factory()->for(ReservationUnit::factory()->checkedIn(), 'reservationUnit')->create();

    expect(removeAddOn(User::factory()->create(), $addOn))->toBeTrue();
});

test('it keeps the add-ons of a unit that is not open', function (string $state) {
    $addOn = UnitAddOn::factory()->for(ReservationUnit::factory()->{$state}(), 'reservationUnit')->create();

    expect(removeAddOn(User::factory()->create(), $addOn))->toBeFalse()
        ->and(UnitAddOn::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
})->with(['cancelled', 'noShow', 'checkedOut']);

test('it refuses a posted add-on and keeps the row and the log empty', function () {
    $addOn = UnitAddOn::factory()->posted()->create();

    expect(removeAddOn(User::factory()->create(), $addOn))->toBeFalse()
        ->and(UnitAddOn::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});
