<?php

use App\Actions\FrontDesk\CancelUnits;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Notifications\PortalBookingCancelled;
use App\Notifications\PortalCancellationRequested;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PortalBookingSetup.php';

test('the list shows this company\'s bookings with the totals, newest first', function () {
    $setup = portalBookingSetup();
    portalAutoAddOn($setup['property']);
    $first = portalMakeBooking($setup, ['external_reference' => 'BT-1', 'expected_total' => '210.00']);
    $second = portalMakeBooking($setup, ['external_reference' => 'BT-2', 'group_name' => 'Tour BT-2', 'expected_total' => '210.00']);

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.index', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/bookings/index')
            ->has('bookings.data', 2)
            ->where('bookings.data.0.id', $second->id)
            ->where('bookings.data.0.title', 'Tour BT-2')
            ->where('bookings.data.0.externalReference', 'BT-2')
            ->where('bookings.data.0.rooms', 1)
            ->where('bookings.data.0.total', '210.00')
            ->where('bookings.data.0.firstArrival', '2026-10-20')
            ->where('bookings.data.0.status.label', 'Confirmed')
            ->where('bookings.data.1.id', $first->id)
            ->where('bookings.data.1.title', 'Guest 1'));
});

test('the list leaves out other companies and other properties', function () {
    $setup = portalBookingSetup();
    $mine = portalMakeBooking($setup);
    $other = Company::factory()->for($setup['property'])->create();
    Reservation::factory()->for($setup['property'])->company($other)->create();
    Reservation::factory()->company()->create();

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.index', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1)->where('bookings.data.0.id', $mine->id));
});

test('the search matches our reference or the company\'s own', function () {
    $setup = portalBookingSetup();
    $first = portalMakeBooking($setup, ['external_reference' => 'BT-2026-118']);
    portalMakeBooking($setup, ['external_reference' => 'XY-7']);

    foreach (['BT-2026', 'bt-2026-118', $first->reference] as $term) {
        $this->actingAs($setup['portalUser'])
            ->get(route('portal.bookings.index', ['property' => $setup['property'], 'search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1)->where('bookings.data.0.id', $first->id)->where('search', $term));
    }

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.index', ['property' => $setup['property'], 'search' => 'nothing']))
        ->assertInertia(fn (Assert $page) => $page->has('bookings.data', 0));
});

test('the list has 20 to a page', function () {
    $setup = portalBookingSetup();
    Reservation::factory(21)->for($setup['property'])->company($setup['company'])->create();

    $this->actingAs($setup['portalUser'])
        ->get(route('portal.bookings.index', $setup['property']))
        ->assertInertia(fn (Assert $page) => $page->has('bookings.data', 20)->where('bookings.meta.total', 21));
});

test('the number of queries does not grow with the bookings on the page', function () {
    $queries = function (int $bookings): int {
        $setup = portalBookingSetup();

        foreach (range(1, $bookings) as $ignored) {
            $reservation = Reservation::factory()->for($setup['property'])->company($setup['company'])->create();
            ReservationUnit::factory()->for($reservation)->withNights()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id]);
        }

        $this->actingAs($setup['portalUser'])->get(route('portal.bookings.index', $setup['property']));
        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->actingAs($setup['portalUser'])->get(route('portal.bookings.index', $setup['property']))->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading(false);
        }
    };

    expect($queries(6))->toBe($queries(1));
});

test('the detail shows rooms, plans, guests, add-ons, total and references and no staff notes', function () {
    $setup = portalBookingSetup();
    portalAutoAddOn($setup['property']);
    $reservation = portalMakeBooking($setup, ['external_reference' => 'BT-9', 'expected_total' => '210.00']);
    $reservation->forceFill(['notes' => 'VIP, staff only'])->save();

    $response = $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', ['property' => $setup['property'], 'reservation' => $reservation->id]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('portal/bookings/show')
        ->where('booking.reference', $reservation->reference)
        ->where('booking.externalReference', 'BT-9')
        ->where('booking.total', '210.00')
        ->where('booking.units.0.ratePlan', $setup['plan']->name)
        ->where('booking.units.0.guestNames', ['Guest 1'])
        ->where('booking.units.0.addOns.0.name', 'Cleaning fee')
        ->where('booking.units.0.nights', 2)
        ->where('booking.guests.0.fullName', 'Guest 1')
        ->where('booking.canEditGuests', true)
        ->where('companySuspended', false));

    expect($response->getContent())->not->toContain('staff only');
});

test('another company\'s or property\'s booking is a 404 on every page', function () {
    $setup = portalBookingSetup();
    $other = Company::factory()->for($setup['property'])->create();
    $theirs = Reservation::factory()->for($setup['property'])->company($other)->create();
    $elsewhere = Reservation::factory()->company()->create();
    $guest = ['guests' => [['guest_id' => $theirs->booker_guest_id, 'full_name' => 'Hijack']]];

    foreach ([$theirs, $elsewhere] as $reservation) {
        $params = ['property' => $setup['property'], 'reservation' => $reservation->id];

        $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', $params))->assertNotFound();
        $this->actingAs($setup['portalUser'])->put(route('portal.bookings.guests.update', $params), $guest)->assertNotFound();
        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancel', $params))->assertNotFound();
        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancellation-request', $params))->assertNotFound();
    }

    expect(Guest::query()->find($theirs->booker_guest_id)->full_name)->not->toBe('Hijack');
});

test('the pages need an Active link at the property in the URL', function () {
    $setup = portalBookingSetup();
    $reservation = portalMakeBooking($setup);
    $otherProperty = Property::factory()->create();
    $stranger = portalUser();

    $this->actingAs($setup['portalUser'])->get(route('portal.bookings.index', $otherProperty))->assertNotFound();
    $this->actingAs($stranger)->get(route('portal.bookings.index', $setup['property']))->assertNotFound();
    $this->actingAs($stranger)->get(route('portal.bookings.show', ['property' => $setup['property'], 'reservation' => $reservation->id]))->assertNotFound();
});

describe('edit guests', function () {
    test('the guests of the booking change their name, phone and ID', function () {
        $setup = portalBookingSetup();
        $reservation = portalMakeBooking($setup);
        $guest = $reservation->booker;

        $this->actingAs($setup['portalUser'])
            ->put(route('portal.bookings.guests.update', ['property' => $setup['property'], 'reservation' => $reservation->id]), ['guests' => [
                ['guest_id' => $guest->id, 'full_name' => 'Tan Wei Ming', 'phone' => '012 345 6789', 'id_type' => 'mykad', 'id_number' => '900101-14-1234'],
            ]])
            ->assertSessionHasNoErrors();

        $guest->refresh();

        expect($guest->full_name)->toBe('Tan Wei Ming')
            ->and($guest->phone)->toBe('+60123456789')
            ->and($guest->documents()->sole()->number)->toBe('900101141234');

        $this->actingAs($setup['portalUser'])
            ->put(route('portal.bookings.guests.update', ['property' => $setup['property'], 'reservation' => $reservation->id]), ['guests' => [
                ['guest_id' => $guest->id, 'full_name' => 'Tan Wei Ming', 'phone' => null, 'id_type' => 'passport', 'id_number' => 'A1234567'],
            ]]);

        $document = $guest->documents()->sole();

        expect($document->number)->toBe('A1234567')
            ->and($document->type->value)->toBe('passport')
            ->and($guest->refresh()->phone)->toBeNull();

        $log = Activity::query()->where('event', 'reservation.guests_updated')->latest('id')->first();

        expect($log->causer_id)->toBe($setup['portalUser']->id)
            ->and($log->subject_id)->toBe($reservation->id);
    });

    test('a guest that is not on the booking is refused and nothing changes', function () {
        $setup = portalBookingSetup();
        $reservation = portalMakeBooking($setup);

        $this->actingAs($setup['portalUser'])
            ->put(route('portal.bookings.guests.update', ['property' => $setup['property'], 'reservation' => $reservation->id]), ['guests' => [
                ['guest_id' => $reservation->booker_guest_id, 'full_name' => 'Changed'],
                ['guest_id' => $setup['guest']->id, 'full_name' => 'Hijack'],
            ]])
            ->assertInertiaFlash('toast.type', 'error');

        expect($reservation->booker->refresh()->full_name)->toBe('Guest 1')
            ->and($setup['guest']->refresh()->full_name)->toBe('Tan Wei Ming');
    });

    test('a name is required and a MyKad has 12 digits', function () {
        $setup = portalBookingSetup();
        $reservation = portalMakeBooking($setup);

        $this->actingAs($setup['portalUser'])
            ->put(route('portal.bookings.guests.update', ['property' => $setup['property'], 'reservation' => $reservation->id]), ['guests' => [
                ['guest_id' => $reservation->booker_guest_id, 'full_name' => '', 'id_type' => 'mykad', 'id_number' => '123456'],
            ]])
            ->assertSessionHasErrors(['guests.0.full_name', 'guests.0.id_number']);
    });

    test('guests change until check-in and not after the arrival date', function (string $businessDate, ?UnitStatus $status, bool $allowed) {
        $setup = portalBookingSetup();
        $reservation = portalMakeBooking($setup);
        BusinessDate::query()->update(['date' => $businessDate]);

        if ($status !== null) {
            $reservation->units()->update(['status' => $status, 'checked_in_at' => now()]);
            $reservation->forceFill(['status' => ReservationStatus::InHouse])->save();
        }

        $this->actingAs($setup['portalUser'])
            ->put(route('portal.bookings.guests.update', ['property' => $setup['property'], 'reservation' => $reservation->id]), ['guests' => [
                ['guest_id' => $reservation->booker_guest_id, 'full_name' => 'Changed'],
            ]]);

        expect($reservation->booker->refresh()->full_name)->toBe($allowed ? 'Changed' : 'Guest 1');
    })->with([
        'before arrival' => ['2026-10-14', null, true],
        'on the arrival date' => ['2026-10-20', null, true],
        'after the arrival date' => ['2026-10-21', null, false],
        'checked in' => ['2026-10-20', UnitStatus::CheckedIn, false],
    ]);
});

describe('cancel and request', function () {
    test('on or before the cut-off the company cancels free', function (int $cutoffDays) {
        Notification::fake();
        $setup = portalBookingSetup();
        $setup['company']->forceFill(['portal_cancel_cutoff_days' => $cutoffDays])->save();
        $reservation = portalMakeBooking($setup);

        $this->actingAs($setup['portalUser'])
            ->post(route('portal.bookings.cancel', ['property' => $setup['property'], 'reservation' => $reservation->id]))
            ->assertInertiaFlash('toast.type', 'success');

        $log = Activity::query()->where('event', 'reservation.cancelled')->sole();

        expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled)
            ->and($reservation->units()->where('status', '<>', UnitStatus::Cancelled->value)->exists())->toBeFalse()
            ->and($log->causer_id)->toBe($setup['portalUser']->id)
            ->and($log->properties['did_not_charge'])->toBeTrue()
            ->and($log->properties['reason'])->toBe('Cancelled by the company in the portal')
            ->and($log->properties['total_fee'])->toBe('0.00');
        Notification::assertSentTo($setup['user'], PortalBookingCancelled::class);
    })->with([
        'a week before the cut-off day' => [5],
        'on the cut-off day' => [6],
    ]);

    test('after the cut-off the company can only ask, and cancelling is refused', function (?int $cutoffDays) {
        Notification::fake();
        $setup = portalBookingSetup();
        $setup['company']->forceFill(['portal_cancel_cutoff_days' => $cutoffDays])->save();
        $reservation = portalMakeBooking($setup);
        $params = ['property' => $setup['property'], 'reservation' => $reservation->id];

        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancel', $params))->assertInertiaFlash('toast.type', 'error');
        expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);

        $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', $params))
            ->assertInertia(fn (Assert $page) => $page->where('booking.cancelMode', 'request'));

        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancellation-request', $params))->assertInertiaFlash('toast.type', 'success');

        $log = Activity::query()->where('event', 'reservation.cancellation_requested')->sole();

        expect($reservation->refresh()->cancellation_requested_at)->not->toBeNull()
            ->and($reservation->cancellation_requested_by)->toBe($setup['portalUser']->id)
            ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
            ->and($log->causer_id)->toBe($setup['portalUser']->id);
        Notification::assertSentTo($setup['user'], PortalCancellationRequested::class);
    })->with([
        'after the cut-off' => [7],
        'no cut-off' => [null],
    ]);

    test('a request is made once', function () {
        Notification::fake();
        $setup = portalBookingSetup();
        $reservation = portalMakeBooking($setup);
        $params = ['property' => $setup['property'], 'reservation' => $reservation->id];

        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancellation-request', $params));
        $firstAt = $reservation->refresh()->cancellation_requested_at;
        $this->travel(1)->hour();

        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancellation-request', $params))->assertInertiaFlash('toast.type', 'error');

        expect($reservation->refresh()->cancellation_requested_at->equalTo($firstAt))->toBeTrue()
            ->and(Activity::query()->where('event', 'reservation.cancellation_requested')->count())->toBe(1);
        Notification::assertSentToTimes($setup['user'], PortalCancellationRequested::class, 1);
    });

    test('a request inside the free window is refused: the company cancels itself', function () {
        $setup = portalBookingSetup();
        $setup['company']->forceFill(['portal_cancel_cutoff_days' => 3])->save();
        $reservation = portalMakeBooking($setup);

        $this->actingAs($setup['portalUser'])
            ->post(route('portal.bookings.cancellation-request', ['property' => $setup['property'], 'reservation' => $reservation->id]))
            ->assertInertiaFlash('toast.type', 'error');

        expect($reservation->refresh()->cancellation_requested_at)->toBeNull();
    });

    test('staff cancelling resolves the request', function () {
        $setup = portalBookingSetup();
        $reservation = portalMakeBooking($setup);
        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancellation-request', ['property' => $setup['property'], 'reservation' => $reservation->id]));

        app(CancelUnits::class)($setup['user'], $reservation, $reservation->units()->pluck('id')->all(), true, 'Agreed');

        expect($reservation->refresh()->cancellation_requested_at)->toBeNull()
            ->and($reservation->status)->toBe(ReservationStatus::Cancelled);
    });

    test('after check-in neither cancel nor request is possible', function () {
        $setup = portalBookingSetup();
        $setup['company']->forceFill(['portal_cancel_cutoff_days' => 5])->save();
        $reservation = portalMakeBooking($setup);
        $reservation->units()->update(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);
        $reservation->forceFill(['status' => ReservationStatus::InHouse])->save();
        $params = ['property' => $setup['property'], 'reservation' => $reservation->id];

        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancel', $params))->assertInertiaFlash('toast.type', 'error');
        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancellation-request', $params))->assertInertiaFlash('toast.type', 'error');
        $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', $params))
            ->assertInertia(fn (Assert $page) => $page->where('booking.cancelMode', 'closed')->where('booking.canEditGuests', false));

        expect($reservation->refresh()->status)->toBe(ReservationStatus::InHouse)
            ->and($reservation->cancellation_requested_at)->toBeNull();
    });

    test('a cancelled booking cannot be cancelled again', function () {
        $setup = portalBookingSetup();
        $setup['company']->forceFill(['portal_cancel_cutoff_days' => 5])->save();
        $reservation = portalMakeBooking($setup);
        $params = ['property' => $setup['property'], 'reservation' => $reservation->id];
        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancel', $params));

        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancel', $params))->assertInertiaFlash('toast.type', 'error');
        $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', $params))
            ->assertInertia(fn (Assert $page) => $page->where('booking.cancelMode', 'closed')->where('booking.status.label', 'Cancelled'));
    });

    test('the page shows the last day to cancel free', function () {
        $setup = portalBookingSetup();
        $setup['company']->forceFill(['portal_cancel_cutoff_days' => 5])->save();
        $reservation = portalMakeBooking($setup);

        $this->actingAs($setup['portalUser'])
            ->get(route('portal.bookings.show', ['property' => $setup['property'], 'reservation' => $reservation->id]))
            ->assertInertia(fn (Assert $page) => $page->where('booking.cancelMode', 'free')->where('booking.freeUntil', '2026-10-15'));
    });
});

describe('a Suspended company', function () {
    test('sees its booking but cannot edit, cancel or request', function () {
        $setup = portalBookingSetup();
        $setup['company']->forceFill(['portal_cancel_cutoff_days' => 5])->save();
        $reservation = portalMakeBooking($setup);
        $setup['company']->forceFill(['status' => 'suspended'])->save();
        $params = ['property' => $setup['property'], 'reservation' => $reservation->id];

        $this->actingAs($setup['portalUser'])->get(route('portal.bookings.index', $setup['property']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('companySuspended', true)->has('bookings.data', 1));
        $this->actingAs($setup['portalUser'])->get(route('portal.bookings.show', $params))
            ->assertInertia(fn (Assert $page) => $page->where('companySuspended', true)->where('booking.canEditGuests', false)->where('booking.cancelMode', 'closed'));

        $this->actingAs($setup['portalUser'])->put(route('portal.bookings.guests.update', $params), ['guests' => [['guest_id' => $reservation->booker_guest_id, 'full_name' => 'Changed']]])->assertInertiaFlash('toast.type', 'error');
        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancel', $params))->assertInertiaFlash('toast.type', 'error');
        $this->actingAs($setup['portalUser'])->post(route('portal.bookings.cancellation-request', $params))->assertInertiaFlash('toast.type', 'error');

        expect($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($reservation->cancellation_requested_at)->toBeNull()
            ->and($reservation->booker->refresh()->full_name)->toBe('Guest 1');
    });
});

test('staff cannot open the portal bookings', function () {
    $setup = portalBookingSetup();

    $this->actingAs($setup['user'])->get(route('portal.bookings.index', $setup['property']))->assertNotFound();
});
