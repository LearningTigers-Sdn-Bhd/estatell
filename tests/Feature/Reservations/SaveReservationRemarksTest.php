<?php

use App\Actions\Reservations\SaveReservationRemarks;
use App\Enums\DefaultRoleEnum;
use App\Enums\RemarkKind;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use App\Models\ReservationUnit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, property: Property, reservation: Reservation}
 */
function remarksSetup(DefaultRoleEnum $role = DefaultRoleEnum::FrontDesk): array
{
    $user = User::factory()->withRole($role)->create();
    PropertySetting::factory()->for($user->property)->create();
    $reservation = Reservation::factory()->create(['property_id' => $user->property_id]);
    ReservationUnit::factory()->for($reservation)->create();

    return ['user' => $user, 'property' => $user->property, 'reservation' => $reservation];
}

function remarksUrl(Property $property, Reservation $reservation): string
{
    return route('reservations.remarks.update', ['property' => $property->code, 'reservation' => $reservation->id]);
}

/**
 * @return array<string, string>
 */
function savedRemarks(Reservation $reservation): array
{
    return $reservation->remarks()->toBase()->orderBy('kind')->pluck('body', 'kind')->all();
}

test('saving adds, changes and removes remarks and logs only the kinds', function () {
    $setup = remarksSetup();
    ReservationRemark::factory()->for($setup['reservation'])->create(['kind' => RemarkKind::Housekeeping, 'body' => 'Swan towel']);
    ReservationRemark::factory()->for($setup['reservation'])->create(['kind' => RemarkKind::Payment, 'body' => 'Collect TTx']);

    app(SaveReservationRemarks::class)($setup['user'], $setup['reservation'], ['housekeeping' => 'Two swan towels', 'dietary' => 'Allergic to prawn']);

    $activity = Activity::query()->where('event', 'reservation.remarks_updated')->sole();

    expect(savedRemarks($setup['reservation']))->toBe(['dietary' => 'Allergic to prawn', 'housekeeping' => 'Two swan towels'])
        ->and($activity->properties->all())->toBe(['property_id' => $setup['property']->id, 'kinds' => ['housekeeping', 'dietary', 'payment']]);
});

test('saving the same remarks logs nothing', function () {
    $setup = remarksSetup();
    ReservationRemark::factory()->for($setup['reservation'])->create(['kind' => RemarkKind::Dietary, 'body' => 'Vegan']);

    app(SaveReservationRemarks::class)($setup['user'], $setup['reservation'], ['dietary' => 'Vegan']);

    expect(Activity::query()->where('event', 'reservation.remarks_updated')->exists())->toBeFalse();
});

test('staff save remarks from the reservation page and see them on it', function () {
    $setup = remarksSetup();
    $url = remarksUrl($setup['property'], $setup['reservation']);

    $this->actingAs($setup['user'])->put($url, ['remarks' => ['package' => str_repeat('x', 501)]])
        ->assertSessionHasErrors(['remarks.package']);

    $this->put($url, ['remarks' => ['tour' => 'TK01']])->assertSessionHasErrors(['remarks']);

    $this->put($url, ['remarks' => ['package' => ' TK01 - Sandakan Nature Tours ', 'dietary' => ' ']])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Remarks saved.');

    expect(savedRemarks($setup['reservation']))->toBe(['package' => 'TK01 - Sandakan Nature Tours']);

    $this->get(route('reservations.activity', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('activity.data', fn ($entries) => collect($entries)->pluck('text')->contains('Package remarks updated')));

    $this->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reservation.remarks', [['kind' => 'package', 'label' => 'Package remarks', 'body' => 'TK01 - Sandakan Nature Tours']])
            ->has('remarkKinds', 4));
});

test('remarks need reservations.manage and another property\'s reservation is not found', function () {
    $setup = remarksSetup(DefaultRoleEnum::Finance);
    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create();

    $this->actingAs($setup['user'])->put(remarksUrl($setup['property'], $setup['reservation']), ['remarks' => ['dietary' => 'Vegan']])->assertForbidden();
    $this->actingAs($owner)->put(remarksUrl($owner->property, $setup['reservation']), ['remarks' => ['dietary' => 'Vegan']])->assertNotFound();

    expect(ReservationRemark::query()->exists())->toBeFalse();
});
