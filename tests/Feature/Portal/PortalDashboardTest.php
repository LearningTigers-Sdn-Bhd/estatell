<?php

use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalSetup.php';

function portalArrival(Property $property, $company, string $date, string $status = 'confirmed'): Reservation
{
    $reservation = Reservation::factory()->for($property)->company($company)->create();
    ReservationUnit::factory()->for($reservation)->create(['arrival_date' => $date, 'departure_date' => date('Y-m-d', strtotime($date.' +2 days')), 'status' => $status]);

    return $reservation;
}

test('the dashboard shows pending invitations and the arrivals of the next 7 days', function () {
    $user = portalUser();
    $sandbay = Property::factory()->create(['name' => 'Sandbay Resort']);
    BusinessDate::factory()->for($sandbay)->create(['date' => '2026-10-14']);
    [$company] = portalLinkFor($user, $sandbay);
    $soon = portalArrival($sandbay, $company, '2026-10-16');
    $edge = portalArrival($sandbay, $company, '2026-10-21');
    portalArrival($sandbay, $company, '2026-10-22');
    portalArrival($sandbay, $company, '2026-10-13');
    portalArrival($sandbay, $company, '2026-10-15', 'cancelled');

    portalLinkFor($user, Property::factory()->create(['name' => 'Tip Top Hostel']), 'pending', 'Borneo Travel Tip Top');

    $this->actingAs($user)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/index')
            ->has('invitations', 1)
            ->where('invitations.0.propertyName', 'Tip Top Hostel')
            ->has('arrivals', 2)
            ->where('arrivals.0.reservationId', $soon->id)
            ->where('arrivals.0.propertyCode', $sandbay->code)
            ->where('arrivals.0.propertyName', 'Sandbay Resort')
            ->where('arrivals.0.reference', $soon->reference)
            ->where('arrivals.0.arrivalDate', '2026-10-16')
            ->where('arrivals.1.reservationId', $edge->id)
        );
});

test('the arrivals leave out other companies, other properties, Revoked links and properties that are not Live', function () {
    $user = portalUser();
    $property = Property::factory()->create();
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-14']);
    [$company] = portalLinkFor($user, $property);
    $own = portalArrival($property, $company, '2026-10-15');

    [$otherCompany] = portalLinkFor(portalUser(), $property, 'active', 'Someone Else');
    portalArrival($property, $otherCompany, '2026-10-15');

    $otherProperty = Property::factory()->create();
    BusinessDate::factory()->for($otherProperty)->create(['date' => '2026-10-14']);
    [$strangerCompany] = portalLinkFor(portalUser(), $otherProperty);
    portalArrival($otherProperty, $strangerCompany, '2026-10-15');

    $revoked = Property::factory()->create();
    BusinessDate::factory()->for($revoked)->create(['date' => '2026-10-14']);
    [$revokedCompany] = portalLinkFor($user, $revoked, 'revoked');
    portalArrival($revoked, $revokedCompany, '2026-10-15');

    $onboarding = Property::factory()->onboarding()->create();
    BusinessDate::factory()->for($onboarding)->create(['date' => '2026-10-14']);
    [$onboardingCompany] = portalLinkFor($user, $onboarding);
    portalArrival($onboarding, $onboardingCompany, '2026-10-15');

    $this->actingAs($user)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('arrivals', 1)
            ->where('arrivals.0.reservationId', $own->id)
        );
});

test('the dashboard has empty lists for a new company user', function () {
    $this->actingAs(portalUser())
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page->has('invitations', 0)->has('arrivals', 0));
});

test('the dashboard takes the same number of queries for more properties', function () {
    $queries = function (int $properties): int {
        $user = portalUser();

        foreach (range(1, $properties) as $number) {
            $property = Property::factory()->create();
            BusinessDate::factory()->for($property)->create(['date' => '2026-10-14']);
            [$company] = portalLinkFor($user, $property, 'active', "Company {$number}");
            portalArrival($property, $company, '2026-10-15');
        }

        $this->actingAs($user)->get(route('portal.index'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('portal.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queries(4))->toBe($queries(1));
});

test('only company users open the dashboard', function () {
    $this->actingAs(User::factory()->owner()->create())->get(route('portal.index'))->assertNotFound();
});

test('the dashboard lists the 5 newest bookings of the user\'s own companies', function () {
    $user = portalUser();
    $property = Property::factory()->create();
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-14']);
    [$company] = portalLinkFor($user, $property);
    $mine = collect(range(1, 6))->map(fn () => portalArrival($property, $company, '2026-12-01'));

    [$otherCompany] = portalLinkFor(portalUser(), $property, 'active', 'Someone Else');
    portalArrival($property, $otherCompany, '2026-12-01');

    $this->actingAs($user)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentBookings', 5)
            ->where('recentBookings.0.reservationId', $mine->last()->id)
            ->where('recentBookings.4.reservationId', $mine[1]->id)
            ->where('recentBookings.0.propertyCode', $property->code)
        );
});

test('recent bookings match each link\'s company and property as a pair', function () {
    $user = portalUser();
    $first = Property::factory()->create();
    $second = Property::factory()->create();
    [$firstCompany] = portalLinkFor($user, $first, 'active', 'First Co');
    [$secondCompany] = portalLinkFor($user, $second, 'active', 'Second Co');

    $crossed = Reservation::factory()->for($first)->create(['company_id' => $secondCompany->id]);
    ReservationUnit::factory()->for($crossed)->create();

    $this->actingAs($user)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page->has('recentBookings', 0));

    expect($firstCompany->property_id)->toBe($first->id);
});
