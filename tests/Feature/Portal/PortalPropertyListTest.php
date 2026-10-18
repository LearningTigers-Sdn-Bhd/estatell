<?php

use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/PortalSetup.php';

test('the list shows Active links with the open balance and the arrivals of the next 7 days, and the invitations', function () {
    $user = portalUser();
    $sandbay = Property::factory()->create(['name' => 'Sandbay Resort']);
    BusinessDate::factory()->for($sandbay)->create(['date' => '2026-10-14']);
    [$company] = portalLinkFor($user, $sandbay);
    ArInvoice::factory()->for($sandbay)->create(['company_id' => $company->id, 'amount' => '500.00']);
    ArInvoice::factory()->for($sandbay)->void()->create(['company_id' => $company->id, 'amount' => '999.00']);
    ArPayment::factory()->for($sandbay)->create(['company_id' => $company->id, 'amount' => '120.00']);

    $arrivals = [['2026-10-14', 'confirmed'], ['2026-10-21', 'confirmed'], ['2026-10-22', 'confirmed'], ['2026-10-13', 'confirmed'], ['2026-10-16', 'cancelled']];
    foreach ($arrivals as [$date, $status]) {
        $reservation = Reservation::factory()->for($sandbay)->company($company)->create();
        ReservationUnit::factory()->for($reservation)->create(['arrival_date' => $date, 'departure_date' => date('Y-m-d', strtotime($date.' +2 days')), 'status' => $status]);
    }

    $other = Property::factory()->create(['name' => 'Tip Top Hostel']);
    portalLinkFor($user, $other, 'pending', 'Borneo Travel Tip Top');

    $this->actingAs($user)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/index')
            ->has('properties', 1)
            ->where('properties.0.propertyName', 'Sandbay Resort')
            ->where('properties.0.propertyCode', $sandbay->code)
            ->where('properties.0.companyName', 'Borneo Travel')
            ->where('properties.0.state', 'open')
            ->where('properties.0.openBalance', '380.00')
            ->where('properties.0.arrivals', 2)
            ->has('invitations', 1)
            ->where('invitations.0.propertyName', 'Tip Top Hostel')
        );
});

test('links of other users and Revoked links are not listed', function () {
    $user = portalUser();
    $property = Property::factory()->create();
    portalLinkFor($user, $property, 'revoked');
    portalLinkFor(portalUser(), $property, 'active', 'Someone Else');

    $this->actingAs($user)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page->has('properties', 0)->has('invitations', 0));
});

test('an Active link at a property that is not Live or is Suspended is greyed out', function () {
    $user = portalUser();
    portalLinkFor($user, Property::factory()->onboarding()->create(['name' => 'Alpha Lodge']), 'active', 'A Co');
    portalLinkFor($user, Property::factory()->suspended()->create(['name' => 'Beta Lodge']), 'active', 'B Co');

    $this->actingAs($user)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('properties.0.state', 'not_open_yet')
            ->where('properties.0.stateLabel', 'Not open yet')
            ->where('properties.1.state', 'suspended')
            ->where('properties.1.stateLabel', 'Suspended')
        );
});

test('only company users open the list', function () {
    $this->actingAs(User::factory()->owner()->create())->get(route('portal.index'))->assertNotFound();
});

test('the number of queries does not grow with the number of properties', function () {
    $queries = function (int $properties): int {
        $user = portalUser();

        foreach (range(1, $properties) as $number) {
            $property = Property::factory()->create();
            BusinessDate::factory()->for($property)->create();
            [$company] = portalLinkFor($user, $property, 'active', "Company {$number}");
            ArInvoice::factory()->for($property)->create(['company_id' => $company->id]);
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
