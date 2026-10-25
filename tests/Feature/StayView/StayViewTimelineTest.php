<?php

use App\Enums\DefaultRoleEnum;
use App\Models\BusinessDate;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/StayViewHelpers.php';

function timelineSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);

    return [$owner, $property];
}

test('the timeline shows a 14 day window from today with rows, stays, blocks and filters', function () {
    [$owner, $property] = timelineSetup();
    $room = Room::factory()->for($property)->create(['number' => '201']);
    stayViewUnit($property, $room, guestName: 'Ahmad Faris');

    $this->actingAs($owner)
        ->get(route('front-desk.timeline', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('front-desk/timeline')
            ->where('window.from', '2026-10-14')
            ->where('window.to', '2026-10-27')
            ->where('window.businessDate', '2026-10-14')
            ->has('rooms', 1)
            ->where('rooms.0.label', '201')
            ->has('stays', 1)
            ->where('stays.0.guestLabel', 'Ahmad Faris')
            ->where('blocks', [])
            ->where('dayUses', [])
            ->where('filters.groupBy', 'room_group')
            ->where('filters.roomGroupIds', [])
            ->has('groupingOptions', 3)
            ->has('statusOptions', 3));
});

test('the window and filters come from the query', function () {
    [$owner, $property] = timelineSetup();
    Room::factory()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('front-desk.timeline', [$property->code, 'from' => '2026-10-20', 'to' => '2026-10-22', 'groups' => [3], 'types' => [4], 'statuses' => ['dirty'], 'q' => 'Ahmad', 'group_by' => 'none']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('window.from', '2026-10-20')
            ->where('window.to', '2026-10-22')
            ->where('filters.roomGroupIds', [3])
            ->where('filters.roomTypeIds', [4])
            ->where('filters.statuses', ['dirty'])
            ->where('filters.search', 'Ahmad')
            ->where('filters.groupBy', 'none'));
});

test('a window longer than 30 days or a bad filter is refused', function () {
    [$owner, $property] = timelineSetup();

    $this->actingAs($owner)
        ->get(route('front-desk.timeline', [$property->code, 'from' => '2026-10-01', 'to' => '2026-11-15']))
        ->assertSessionHasErrors('to');
    $this->actingAs($owner)
        ->get(route('front-desk.timeline', [$property->code, 'group_by' => 'colour', 'statuses' => ['asleep']]))
        ->assertSessionHasErrors(['group_by', 'statuses.0']);
});

test('the rate row lists the nightly plans in order and leaves out package and day use plans', function () {
    [$owner, $property] = timelineSetup();
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $walkIn = RatePlan::factory()->for($property)->create(['name' => 'Walk-in', 'position' => 1]);
    $corporate = RatePlan::factory()->for($property)->create(['name' => 'Corporate', 'position' => 2]);
    $package = RatePlan::factory()->for($property)->package()->create(['name' => 'Honeymoon', 'position' => 3]);
    $dayUse = RatePlan::factory()->for($property)->dayUse()->create(['name' => 'Day use', 'position' => 4]);

    foreach ([$walkIn, $corporate, $package, $dayUse] as $plan) {
        $plan->roomTypes()->attach($roomType);
    }

    Rate::factory()->create(['rate_plan_id' => $walkIn->id, 'room_type_id' => $roomType->id, 'date' => '2026-10-14', 'amount' => '240.00']);
    Rate::factory()->stopSell()->create(['rate_plan_id' => $walkIn->id, 'room_type_id' => $roomType->id, 'date' => '2026-10-15', 'amount' => '280.00']);
    Rate::factory()->create(['rate_plan_id' => $corporate->id, 'room_type_id' => $roomType->id, 'date' => '2026-10-14', 'amount' => '216.00']);

    $this->actingAs($owner)
        ->get(route('front-desk.timeline', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rates', 2)
            ->where('rates.0.ratePlanName', 'Walk-in')
            ->where('rates.0.roomTypeName', 'Deluxe')
            ->where('rates.0.days.0', ['date' => '2026-10-14', 'amount' => '240.00', 'closed' => false])
            ->where('rates.0.days.1', ['date' => '2026-10-15', 'amount' => '280.00', 'closed' => true])
            ->where('rates.1.ratePlanName', 'Corporate'));
});

test('the Timeline View sits in the Overview group and is active on its page', function () {
    [$owner, $property] = timelineSetup();

    $this->actingAs($owner)
        ->get(route('front-desk.timeline', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'front-desk')
            ->where('navigation.contexts.0.groups', function ($groups) {
                $overview = collect($groups)->firstWhere('label', 'Overview');
                $item = collect($overview['items'] ?? [])->firstWhere('title', 'Timeline View');

                expect($item['active'] ?? null)->toBeTrue()
                    ->and(collect($overview['items'])->pluck('title')->all())->toBe(['Dashboard', 'Timeline View']);

                return true;
            }));
});

test('the Overview group has no Timeline View for staff without front-desk.view', function () {
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();
    PropertySetting::factory()->for($finance->property)->create();

    $this->actingAs($finance)
        ->get(route('dashboard', $finance->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.0.groups', function ($groups) {
                $overview = collect($groups)->firstWhere('label', 'Overview');

                expect(collect($overview['items'] ?? [])->pluck('title')->all())->not->toContain('Timeline View');

                return true;
            }));
});

test('the timeline carries the block room props, and canBlockRooms needs rooms.block', function () {
    [$owner, $property] = timelineSetup();
    $viewer = User::factory()->for($property)->withPermissions(['front-desk.view'])->create();

    $this->actingAs($owner)
        ->get(route('front-desk.timeline', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canBlockRooms', true)
            ->has('blockReasons', 4)
            ->where('blockReasons.0.value', 'maintenance')
            ->where('maxBlockNights', 365));

    $this->actingAs($viewer)
        ->get(route('front-desk.timeline', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('canBlockRooms', false));
});

test('staff without front-desk.view cannot open the timeline', function () {
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();

    $this->actingAs($finance)->get(route('front-desk.timeline', $finance->property->code))->assertForbidden();
});

test('front desk staff can open the timeline', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    PropertySetting::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)->get(route('front-desk.timeline', $frontDesk->property->code))->assertOk();
});

test('the timeline is not reachable through another property code', function () {
    [$owner] = timelineSetup();
    $other = User::factory()->owner()->create()->property;

    $this->actingAs($owner)->get(route('front-desk.timeline', $other->code))->assertNotFound();
});

test('the page runs the same number of queries however many stays it shows', function () {
    [$owner, $property] = timelineSetup();
    $reservation = Reservation::factory()->group('ABC Corp')->create(['property_id' => $property->id]);
    $countQueries = function () use ($owner, $property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('front-desk.timeline', $property->code))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $addStay = function (int $index) use ($property, $reservation): void {
        stayViewUnit($property, Room::factory()->for($property)->create(), reservation: $index % 2 === 0 ? $reservation : null, guestName: 'Guest '.$index);
    };

    $addStay(1);
    $countQueries();
    $before = $countQueries();

    foreach (range(2, 7) as $index) {
        $addStay($index);
    }

    expect($countQueries())->toBe($before)
        ->and(ReservationUnit::query()->count())->toBe(7);
});
