<?php

use App\Data\Reports\ReportFilters;
use App\Enums\FacilityBookedBy;
use App\Enums\FacilityBookingStatus;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Features\Facilities;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBoat;
use App\Models\FacilityBooking;
use App\Models\FacilityGuide;
use App\Models\FacilitySession;
use App\Models\FacilitySlotCrew;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * The event setup with the River Safari (by person, 12 places, sessions 06:00–08:00 and 16:00–18:00) and the Night
 * Walk (by person, 20 places, no sessions), an event on 15 Oct 2026, a guide and a 10-seat boat.
 *
 * @return array{property: Property, owner: User, safari: Facility, walk: Facility, event: Event, guide: FacilityGuide, boat: FacilityBoat}
 */
function activityCrewSetup(): array
{
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event] = makeEvent($property, $owner, ['name' => 'Borneo Trails group', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15']);
    $safari = Facility::factory()->create(['property_id' => $property->id, 'name' => 'River Safari', 'booked_by' => FacilityBookedBy::Person, 'capacity' => 12, 'position' => 1]);
    FacilitySession::factory()->for($safari)->create(['starts_at' => '16:00', 'ends_at' => '18:00']);
    FacilitySession::factory()->for($safari)->create(['starts_at' => '06:00', 'ends_at' => '08:00']);
    $walk = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Night Walk', 'booked_by' => FacilityBookedBy::Person, 'capacity' => 20, 'position' => 2]);

    return [
        'property' => $property,
        'owner' => $owner,
        'safari' => $safari,
        'walk' => $walk,
        'event' => $event,
        'guide' => FacilityGuide::factory()->for($property)->create(['name' => 'Jamil', 'phone' => '+60111222333']),
        'boat' => FacilityBoat::factory()->for($property)->create(['name' => 'Kingfisher', 'seats' => 10]),
    ];
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $attributes
 */
function activityCrewBooking(array $setup, Facility $facility, array $attributes = []): FacilityBooking
{
    return FacilityBooking::factory()->create(['event_id' => $setup['event']->id, 'facility_id' => $facility->id, 'booking_date' => '2026-10-15', ...$attributes]);
}

/** @param  array<string, mixed>  $setup */
function activityCrewAssign(array $setup, Facility $facility, string $starts, string $ends, ?int $guideId, ?int $boatId, string $date = '2026-10-15'): string
{
    return route('activities.assign', [$setup['property']->code, $facility->id]).'?'.http_build_query(array_filter([
        'date' => $date, 'starts_at' => $starts, 'ends_at' => $ends,
    ])).($guideId === null ? '' : "&facility_guide_id={$guideId}").($boatId === null ? '' : "&facility_boat_id={$boatId}");
}

test('guides and boats are added and edited on the Facilities settings tabs, with unique names per property', function () {
    $setup = activityCrewSetup();
    $other = activityCrewSetup();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);

    $this->get(route('settings.facilities.guides.index', $code))->assertInertia(fn (Assert $page) => $page
        ->component('settings/facilities/guides')
        ->has('guides', 1)
        ->where('guides.0', ['id' => $setup['guide']->id, 'name' => 'Jamil', 'phone' => '+60111222333', 'active' => true]));

    $this->post(route('settings.facilities.guides.store', $code), ['name' => ' Rosli ', 'phone' => ''])->assertSessionHasNoErrors();
    $this->post(route('settings.facilities.guides.store', $code), ['name' => 'jamil'])->assertSessionHasErrors('name');
    $this->post(route('settings.facilities.boats.store', $code), ['name' => 'Hornbill', 'seats' => '8'])->assertSessionHasNoErrors();
    $this->post(route('settings.facilities.boats.store', $code), ['name' => 'Otter', 'seats' => '0'])->assertSessionHasErrors('seats');
    $this->put(route('settings.facilities.boats.update', [$code, $setup['boat']->id]), ['name' => 'Kingfisher', 'seats' => '10', 'active' => false])->assertSessionHasNoErrors();
    $this->put(route('settings.facilities.guides.update', [$code, $other['guide']->id]), ['name' => 'Stolen'])->assertNotFound();

    expect(FacilityGuide::query()->where('property_id', $setup['property']->id)->pluck('name')->sort()->values()->all())->toBe(['Jamil', 'Rosli'])
        ->and(FacilityBoat::query()->where('property_id', $setup['property']->id)->where('name', 'Hornbill')->value('seats'))->toBe(8)
        ->and($setup['boat']->fresh()->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'facility_guide.created')->count())->toBe(1);

    $frontDesk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::FrontDeskView])->create();
    $this->actingAs($frontDesk)->get(route('settings.facilities.boats.index', $code))->assertForbidden();
});

test('the run sheet lists every session of the activities and the other booked times, with the places booked', function () {
    $setup = activityCrewSetup();
    activityCrewBooking($setup, $setup['safari'], ['starts_at' => '06:00', 'ends_at' => '08:00', 'quantity' => 8]);
    activityCrewBooking($setup, $setup['safari'], ['starts_at' => '06:00', 'ends_at' => '08:00', 'quantity' => 3, 'status' => FacilityBookingStatus::Tentative]);
    activityCrewBooking($setup, $setup['safari'], ['starts_at' => '06:00', 'ends_at' => '08:00', 'quantity' => 5, 'status' => FacilityBookingStatus::Cancelled]);
    activityCrewBooking($setup, $setup['walk'], ['starts_at' => '20:00', 'ends_at' => '22:00', 'quantity' => 4]);
    activityCrewBooking($setup, $setup['safari'], ['starts_at' => '06:00', 'ends_at' => '08:00', 'booking_date' => '2026-10-16']);
    Facility::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Riverside Hall', 'booked_by' => FacilityBookedBy::Item]);

    $this->actingAs($setup['owner'])->get(route('activities.index', [$setup['property']->code, 'date' => '2026-10-15']))->assertInertia(fn (Assert $page) => $page
        ->component('activities/index')
        ->where('canAssign', true)
        ->has('guides', 1)
        ->has('boats', 1)
        ->where('slots', fn ($slots) => collect($slots)->map(fn ($slot) => [$slot['facility'], $slot['startsAt'], $slot['endsAt'], $slot['booked'], $slot['bookings'], $slot['isSession']])->all() === [
            ['River Safari', '06:00', '08:00', 11, 2, true],
            ['River Safari', '16:00', '18:00', 0, 0, true],
            ['Night Walk', '20:00', '22:00', 4, 1, false],
        ]));
});

test('staff pick the guide and boat of a session, and the participant list names them', function () {
    $setup = activityCrewSetup();
    activityCrewBooking($setup, $setup['safari'], ['starts_at' => '06:00', 'ends_at' => '08:00', 'quantity' => 11]);
    $this->actingAs($setup['owner']);

    $this->put(activityCrewAssign($setup, $setup['safari'], '06:00', '08:00', $setup['guide']->id, $setup['boat']->id))->assertSessionHasNoErrors();
    $this->put(activityCrewAssign($setup, $setup['safari'], '06:00', '08:00', $setup['guide']->id, $setup['boat']->id));

    $crew = FacilitySlotCrew::query()->sole();
    expect($crew->facility_guide_id)->toBe($setup['guide']->id)
        ->and($crew->property_id)->toBe($setup['property']->id)
        ->and(Activity::query()->where('event', 'facility.slot_crew_assigned')->count())->toBe(1);

    $this->get(route('activities.index', [$setup['property']->code, 'date' => '2026-10-15']))->assertInertia(fn (Assert $page) => $page
        ->where('slots.0.guideId', $setup['guide']->id)
        ->where('slots.0.boatSeats', 10));

    $report = ReportKey::FacilityBookings->report();
    $filters = new ReportFilters('2026-10-15', '2026-10-15');
    expect($report->sections($report->rows($setup['property'], $filters), $filters)[0]->title)
        ->toBe('River Safari · 15 Oct · 06:00–08:00 · Guide: Jamil · Boat: Kingfisher');

    $this->put(activityCrewAssign($setup, $setup['safari'], '06:00', '08:00', null, null));
    expect(FacilitySlotCrew::query()->count())->toBe(0);

    $this->put(activityCrewAssign($setup, $setup['safari'], '16:00', '18:00', $setup['guide']->id, null))->assertSessionHasNoErrors();
    expect(FacilitySlotCrew::query()->sole()->starts_at)->toBe('16:00:00');
});

test('the pick is refused for an unavailable or another property\'s guide or boat, and for a time that is no slot', function () {
    $setup = activityCrewSetup();
    $other = activityCrewSetup();
    $setup['boat']->update(['active' => false]);
    $this->actingAs($setup['owner']);

    $this->put(activityCrewAssign($setup, $setup['safari'], '06:00', '08:00', $other['guide']->id, null))
        ->assertSessionHas('inertia.flash_data.toast.message', 'That guide is not available. Pick another.');
    $this->put(activityCrewAssign($setup, $setup['safari'], '06:00', '08:00', null, $setup['boat']->id))
        ->assertSessionHas('inertia.flash_data.toast.message', 'That boat is not available. Pick another.');
    $this->put(activityCrewAssign($setup, $setup['safari'], '09:00', '10:00', $setup['guide']->id, null))
        ->assertSessionHas('inertia.flash_data.toast.message', 'This session is no longer on the run sheet. Reload the page.');
    $this->put(activityCrewAssign($setup, $other['safari'], '06:00', '08:00', $setup['guide']->id, null))->assertNotFound();

    expect(FacilitySlotCrew::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'facility.slot_crew_assigned')->count())->toBe(0);
});

test('the run sheet needs front-desk.view, the pick needs facilities.book, and both need the Facilities feature', function () {
    $setup = activityCrewSetup();
    $viewer = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::FrontDeskView])->create();
    $code = $setup['property']->code;

    $this->actingAs($viewer)->get(route('activities.index', [$code, 'date' => '2026-10-15']))->assertInertia(fn (Assert $page) => $page->where('canAssign', false));
    $this->actingAs($viewer)->put(activityCrewAssign($setup, $setup['safari'], '06:00', '08:00', $setup['guide']->id, null))->assertForbidden();
    $this->actingAs(User::factory()->for($setup['property'])->withPermissions([PermissionEnum::GuestsView])->create())->get(route('activities.index', $code))->assertForbidden();

    Feature::for($setup['property'])->deactivate(Facilities::class);
    $this->actingAs($setup['owner'])->get(route('activities.index', $code))->assertNotFound();
    $this->actingAs($setup['owner'])->get(route('settings.facilities.guides.index', $code))->assertNotFound();
});

test('the run sheet costs the same number of queries however many activities and sessions it lists', function () {
    $setup = activityCrewSetup();
    $this->actingAs($setup['owner'])->put(activityCrewAssign($setup, $setup['safari'], '06:00', '08:00', $setup['guide']->id, $setup['boat']->id));
    $count = function () use ($setup): int {
        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($setup['owner'])->get(route('activities.index', [$setup['property']->code, 'date' => '2026-10-15']))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $queries;
    };
    $count();
    $few = $count();

    foreach (range(1, 4) as $index) {
        $facility = Facility::factory()->create(['property_id' => $setup['property']->id, 'booked_by' => FacilityBookedBy::Person, 'capacity' => 10]);
        FacilitySession::factory()->for($facility)->create(['starts_at' => '09:00', 'ends_at' => '10:00']);
        activityCrewBooking($setup, $facility, ['starts_at' => '09:00', 'ends_at' => '10:00']);
        (new FacilitySlotCrew(['facility_id' => $facility->id, 'slot_date' => '2026-10-15', 'starts_at' => '09:00:00', 'ends_at' => '10:00:00', 'facility_boat_id' => $setup['boat']->id]))->forceFill(['property_id' => $setup['property']->id])->save();
    }

    expect($count())->toBe($few);
});
