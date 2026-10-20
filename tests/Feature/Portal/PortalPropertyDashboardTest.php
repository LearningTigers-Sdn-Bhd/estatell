<?php

use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalSetup.php';

function portalDashboardSetup(): array
{
    $user = portalUser();
    $property = Property::factory()->create();
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-14']);
    [$company, $link] = portalLinkFor($user, $property);

    return compact('user', 'property', 'company', 'link');
}

function dashboardBooking(Property $property, $company, string $date, string $status = 'confirmed'): Reservation
{
    $reservation = Reservation::factory()->for($property)->company($company)->create();
    ReservationUnit::factory()->for($reservation)->create(['arrival_date' => $date, 'departure_date' => date('Y-m-d', strtotime($date.' +2 days')), 'status' => $status]);

    return $reservation;
}

test('the stats show the balance, overdue, arrivals, in-house and credit of the company', function () {
    ['user' => $user, 'property' => $property, 'company' => $company] = portalDashboardSetup();
    $company->forceFill(['credit_limit' => '1000.00'])->save();
    ArInvoice::factory()->for($property)->create(['company_id' => $company->id, 'amount' => '300.00', 'due_on' => '2026-10-01']);
    ArInvoice::factory()->for($property)->create(['company_id' => $company->id, 'amount' => '200.00', 'due_on' => '2026-11-03']);
    ArInvoice::factory()->for($property)->void()->create(['company_id' => $company->id, 'amount' => '999.00']);
    dashboardBooking($property, $company, '2026-10-16');
    $staying = Reservation::factory()->for($property)->company($company)->create();
    ReservationUnit::factory()->for($staying)->checkedIn()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);

    $this->actingAs($user)
        ->get(route('portal.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/show')
            ->where('stats.openBalance', '500.00')
            ->where('stats.openInvoices', 2)
            ->where('stats.overdue', '300.00')
            ->where('stats.overdueInvoices', 1)
            ->where('stats.arrivals', 1)
            ->where('stats.inHouse', 1)
            ->where('stats.creditLimit', '1000.00')
            ->where('stats.creditAvailable', '500.00')
            ->where('companyStatus.value', 'active')
            ->where('companySuspended', false)
        );
});

test('a company with no credit limit shows none, and available credit is never below 0', function () {
    ['user' => $user, 'property' => $property, 'company' => $company] = portalDashboardSetup();

    $this->actingAs($user)
        ->get(route('portal.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.creditLimit', null)
            ->where('stats.creditAvailable', null)
        );

    $company->forceFill(['credit_limit' => '100.00'])->save();
    ArInvoice::factory()->for($property)->create(['company_id' => $company->id, 'amount' => '250.00']);

    $this->actingAs($user)
        ->get(route('portal.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('stats.creditAvailable', '0.00'));
});

test('the lists leave out another company\'s and another property\'s rows', function () {
    ['user' => $user, 'property' => $property, 'company' => $company] = portalDashboardSetup();
    $own = dashboardBooking($property, $company, '2026-10-16');
    ArInvoice::factory()->for($property)->create(['company_id' => $company->id, 'number' => 'AR-OWN']);

    [$otherCompany] = portalLinkFor(portalUser(), $property, 'active', 'Someone Else');
    dashboardBooking($property, $otherCompany, '2026-10-16');
    ArInvoice::factory()->for($property)->create(['company_id' => $otherCompany->id, 'number' => 'AR-OTHER']);

    $second = Property::factory()->create();
    BusinessDate::factory()->for($second)->create(['date' => '2026-10-14']);
    [$secondCompany] = portalLinkFor($user, $second, 'active', 'Second Co');
    dashboardBooking($second, $secondCompany, '2026-10-16');
    ArInvoice::factory()->for($second)->create(['company_id' => $secondCompany->id, 'number' => 'AR-SECOND']);

    $this->actingAs($user)
        ->get(route('portal.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('arrivals', 1)
            ->where('arrivals.0.reservationId', $own->id)
            ->has('recentBookings', 1)
            ->where('recentBookings.0.reservationId', $own->id)
            ->has('openInvoices', 1)
            ->where('openInvoices.0.number', 'AR-OWN')
        );
});

test('the lists are limited to 5 while the stats count everything', function () {
    ['user' => $user, 'property' => $property, 'company' => $company] = portalDashboardSetup();

    foreach (range(1, 6) as $number) {
        dashboardBooking($property, $company, '2026-10-16');
        ArInvoice::factory()->for($property)->create(['company_id' => $company->id, 'due_on' => '2026-11-0'.$number]);
    }

    $this->actingAs($user)
        ->get(route('portal.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('arrivals', 5)
            ->has('recentBookings', 5)
            ->has('openInvoices', 5)
            ->where('stats.arrivals', 6)
            ->where('stats.openInvoices', 6)
        );
});

test('a Suspended company still sees its figures and lists but no booking', function () {
    ['user' => $user, 'property' => $property, 'company' => $company] = portalDashboardSetup();
    $company->forceFill(['status' => 'suspended'])->save();
    dashboardBooking($property, $company, '2026-10-16');

    $this->actingAs($user)
        ->get(route('portal.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('companySuspended', true)
            ->where('companyStatus.value', 'suspended')
            ->has('arrivals', 1)
        );
});
