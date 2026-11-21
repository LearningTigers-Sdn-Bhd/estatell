<?php

use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateReservationSalesPerson;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, property: Property, reservation: Reservation, seller: User}
 */
function salesPersonSetup(DefaultRoleEnum $role = DefaultRoleEnum::FrontDesk): array
{
    $user = User::factory()->withRole($role)->create();
    $reservation = Reservation::factory()->create(['property_id' => $user->property_id]);
    ReservationUnit::factory()->for($reservation)->create();
    $seller = User::factory()->for($user->property)->create(['name' => 'Jane']);

    return ['user' => $user, 'property' => $user->property, 'reservation' => $reservation, 'seller' => $seller];
}

function salesPersonUrl(Property $property, Reservation $reservation): string
{
    return route('reservations.sales-person.update', ['property' => $property->code, 'reservation' => $reservation->id]);
}

test('it sets the sales person on a closed reservation too and logs the change', function () {
    $setup = salesPersonSetup();
    $setup['reservation']->forceFill(['status' => ReservationStatus::Completed])->save();

    $result = app(UpdateReservationSalesPerson::class)($setup['user'], $setup['reservation'], $setup['seller']->id);

    $activity = Activity::query()->where('event', 'reservation.sales_person_updated')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['reservation']->fresh()->sales_person_id)->toBe($setup['seller']->id)
        ->and($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->properties['old']['sales_person_id'])->toBeNull()
        ->and($activity->properties['new']['sales_person_id'])->toBe($setup['seller']->id);
});

test('clearing removes the sales person', function () {
    $setup = salesPersonSetup();
    $setup['reservation']->forceFill(['sales_person_id' => $setup['seller']->id])->save();

    app(UpdateReservationSalesPerson::class)($setup['user'], $setup['reservation'], null);

    expect($setup['reservation']->fresh()->sales_person_id)->toBeNull();
});

test('the same sales person is not a change, so a deactivated one stays and nothing is logged', function () {
    $setup = salesPersonSetup();
    $setup['seller']->forceFill(['deactivated_at' => now()])->save();
    $setup['reservation']->forceFill(['sales_person_id' => $setup['seller']->id])->save();

    $result = app(UpdateReservationSalesPerson::class)($setup['user'], $setup['reservation'], $setup['seller']->id);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['reservation']->fresh()->sales_person_id)->toBe($setup['seller']->id)
        ->and(Activity::query()->where('event', 'reservation.sales_person_updated')->exists())->toBeFalse();
});

test('it refuses a user who is not active staff of the property', function (string $kind) {
    $setup = salesPersonSetup();
    $user = match ($kind) {
        'other' => User::factory()->create(),
        'deactivated' => User::factory()->for($setup['property'])->deactivated()->create(),
        'company' => User::factory()->companyUser()->create(),
    };

    $result = app(UpdateReservationSalesPerson::class)($setup['user'], $setup['reservation'], $user->id);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_SALES_PERSON_NOT_AVAILABLE)
        ->and($setup['reservation']->fresh()->sales_person_id)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.sales_person_updated')->exists())->toBeFalse();
})->with([
    'staff of another property' => 'other',
    'deactivated staff' => 'deactivated',
    'a company portal user' => 'company',
]);

test('the sales person route needs reservations.manage', function () {
    $setup = salesPersonSetup(DefaultRoleEnum::Finance);

    $this->actingAs($setup['user'])
        ->put(salesPersonUrl($setup['property'], $setup['reservation']), ['sales_person_id' => $setup['seller']->id])
        ->assertForbidden();

    expect($setup['reservation']->fresh()->sales_person_id)->toBeNull();
});

test('another property\'s reservation is not found', function () {
    $setup = salesPersonSetup();
    $other = Reservation::factory()->create();

    $this->actingAs($setup['user'])
        ->put(salesPersonUrl($setup['property'], $other), ['sales_person_id' => $setup['seller']->id])
        ->assertNotFound();

    expect($other->fresh()->sales_person_id)->toBeNull();
});

test('staff change the sales person and a refusal goes back as a field error', function () {
    $setup = salesPersonSetup();
    $deactivated = User::factory()->for($setup['property'])->deactivated()->create();
    $url = salesPersonUrl($setup['property'], $setup['reservation']);

    $this->actingAs($setup['user'])->put($url, ['sales_person_id' => $setup['seller']->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Sales person saved.');

    expect($setup['reservation']->fresh()->sales_person_id)->toBe($setup['seller']->id);

    $this->put($url, ['sales_person_id' => $deactivated->id])
        ->assertSessionHasErrors(['sales_person_id' => 'This staff member is not available. Pick an active staff member.']);

    expect($setup['reservation']->fresh()->sales_person_id)->toBe($setup['seller']->id);
});

test('the reservation page shows the sales person and lists active staff when the Dialog asks', function () {
    $setup = salesPersonSetup();
    PropertySetting::factory()->for($setup['property'])->create();
    User::factory()->for($setup['property'])->deactivated()->create(['name' => 'Gone']);
    User::factory()->create(['name' => 'Elsewhere']);
    $setup['reservation']->forceFill(['sales_person_id' => $setup['seller']->id])->save();
    $url = route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('reservation.salesPersonId', $setup['seller']->id)
        ->where('reservation.salesPersonName', 'Jane')
        ->missing('salesPeople'));

    $names = $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'salesPeople', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])
        ->json('props.salesPeople.*.name');

    expect($names)->toContain('Jane', $setup['user']->name)
        ->not->toContain('Gone', 'Elsewhere');
});

test('the activity tab names the old and new sales person', function () {
    $setup = salesPersonSetup();
    PropertySetting::factory()->for($setup['property'])->create();
    $mia = User::factory()->for($setup['property'])->create(['name' => 'Mia']);
    $action = app(UpdateReservationSalesPerson::class);
    $action($setup['user'], $setup['reservation'], $setup['seller']->id);
    $action($setup['user'], $setup['reservation'], $mia->id);
    $action($setup['user'], $setup['reservation'], null);

    $this->actingAs($setup['user'])
        ->get(route('reservations.activity', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('activity.data', fn ($entries) => collect($entries)->pluck('text')
            ->intersect(['Sales person: None → Jane', 'Sales person: Jane → Mia', 'Sales person: Mia → None'])->count() === 3));
});
