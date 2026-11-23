<?php

use App\Actions\Housekeeping\UpdateBedStatus;
use App\Actions\Housekeeping\UpdateRoomStatus;
use App\Actions\NightAudit\Steps\UpdateRoomStatuses;
use App\Actions\Rooms\CreateBed;
use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingRefusal;
use App\Enums\HousekeepingStatus;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 0));
});

/**
 * A dorm room 201 with beds A1, A2 and A3, all Clean, at a property on business date 2026-10-12.
 *
 * @return array{owner: User, property: Property, room: Room, beds: list<Bed>}
 */
function dormBedsSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $dorm = RoomType::factory()->for($property)->create(['sell_unit' => SellUnit::Bed, 'max_adults' => 1, 'base_occupancy' => 1]);
    $room = Room::factory()->for($property)->create(['number' => '201', 'room_type_id' => $dorm->id]);
    $beds = array_map(fn (string $label): Bed => Bed::factory()->create(['room_id' => $room->id, 'label' => $label]), ['A1', 'A2', 'A3']);

    return ['owner' => $owner, 'property' => $property, 'room' => $room, 'beds' => $beds];
}

function bedStatusOf(Bed $bed): HousekeepingStatus
{
    return $bed->status()->sole()->status;
}

function dormRoomStatus(Room $room): HousekeepingStatus
{
    return $room->status()->sole()->status;
}

test('a new bed starts with its room status, through the action and the factory', function () {
    $setup = dormBedsSetup();
    $setup['room']->status->update(['status' => HousekeepingStatus::Dirty]);

    $bed = app(CreateBed::class)($setup['owner'], $setup['room'], ['label' => 'A4']);

    expect(bedStatusOf($bed))->toBe(HousekeepingStatus::Dirty)
        ->and(bedStatusOf($setup['beds'][0]))->toBe(HousekeepingStatus::Clean);
});

test('the dorm is Dirty while any bed is, Clean when none is, and Inspected when every bed is', function () {
    $setup = dormBedsSetup();
    [$a1, $a2, $a3] = $setup['beds'];
    $update = app(UpdateBedStatus::class);

    expect($update($setup['owner'], $a1, HousekeepingStatus::Dirty))->toBeNull()
        ->and(dormRoomStatus($setup['room']))->toBe(HousekeepingStatus::Dirty)
        ->and(bedStatusOf($a2))->toBe(HousekeepingStatus::Clean);

    $update($setup['owner'], $a1, HousekeepingStatus::Clean);
    expect(dormRoomStatus($setup['room']))->toBe(HousekeepingStatus::Clean);

    $update($setup['owner'], $a1, HousekeepingStatus::Inspected);
    $update($setup['owner'], $a2, HousekeepingStatus::Inspected);
    expect(dormRoomStatus($setup['room']))->toBe(HousekeepingStatus::Clean);

    $update($setup['owner'], $a3, HousekeepingStatus::Inspected);
    expect(dormRoomStatus($setup['room']))->toBe(HousekeepingStatus::Inspected)
        ->and(Activity::query()->where('event', 'bed_status.changed')->count())->toBe(5);
});

test('setting the whole dorm sets every active bed', function () {
    $setup = dormBedsSetup();
    $setup['beds'][2]->forceFill(['archived_at' => now()])->save();

    app(UpdateRoomStatus::class)($setup['owner'], $setup['room'], HousekeepingStatus::Dirty);

    expect(bedStatusOf($setup['beds'][0]))->toBe(HousekeepingStatus::Dirty)
        ->and(bedStatusOf($setup['beds'][1]))->toBe(HousekeepingStatus::Dirty)
        ->and(bedStatusOf($setup['beds'][2]))->toBe(HousekeepingStatus::Clean);
});

test('a blocked bed or room, or a move the rules do not allow, is refused', function () {
    $setup = dormBedsSetup();
    [$a1, $a2] = $setup['beds'];
    RoomBlock::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'bed_id' => $a1->id, 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-13']);

    expect(app(UpdateBedStatus::class)($setup['owner'], $a1, HousekeepingStatus::Dirty))->toBe(HousekeepingRefusal::OutOfService)
        ->and(app(UpdateBedStatus::class)($setup['owner'], $a2, HousekeepingStatus::Inspected))->toBeNull()
        ->and(app(UpdateBedStatus::class)($setup['owner'], $a2, HousekeepingStatus::Clean))->toBe(HousekeepingRefusal::InvalidTransition)
        ->and(bedStatusOf($a1))->toBe(HousekeepingStatus::Clean);
});

test('the night audit makes the stay-over guests\' beds Dirty, and leaves the empty ones', function () {
    $setup = dormBedsSetup();
    [$a1, $a2] = $setup['beds'];
    $unit = ReservationUnit::factory()->withNights()->create([
        'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id,
        'room_type_id' => $setup['room']->room_type_id,
        'arrival_date' => '2026-10-11',
        'departure_date' => '2026-10-14',
        'status' => UnitStatus::CheckedIn,
        'checked_in_at' => now(),
    ]);
    $unit->nights()->update(['room_id' => null, 'bed_id' => $a1->id]);
    $audit = NightAudit::factory()->create(['property_id' => $setup['property']->id]);

    app(UpdateRoomStatuses::class)->run($audit, CarbonImmutable::parse('2026-10-12'));

    expect(bedStatusOf($a1))->toBe(HousekeepingStatus::Dirty)
        ->and(bedStatusOf($a2))->toBe(HousekeepingStatus::Clean)
        ->and(dormRoomStatus($setup['room']))->toBe(HousekeepingStatus::Dirty);
});

test('the board shows a dorm\'s beds with their moves, and a bed changes through its own route', function () {
    $setup = dormBedsSetup();
    $other = Room::factory()->for($setup['property'])->create();
    $housekeeper = User::factory()->for($setup['property'])->withRole(DefaultRoleEnum::Housekeeping)->create();
    $route = fn (Room $room, Bed $bed): string => route('housekeeping.rooms.beds.status', [$setup['property']->code, $room->id, $bed->id]);

    $this->actingAs($setup['owner'])->get(route('housekeeping.index', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rooms', fn ($rooms) => collect($rooms)->firstWhere('number', '201')['beds'] !== []
                && collect(collect($rooms)->firstWhere('number', '201')['beds'])->pluck('label')->all() === ['A1', 'A2', 'A3']
                && collect(collect($rooms)->firstWhere('number', '201')['beds'][0]['moves'])->pluck('value')->all() === ['dirty', 'inspected']));

    $this->actingAs($housekeeper)->put($route($setup['room'], $setup['beds'][0]), ['status' => 'dirty'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Bed 201 A1 is now Dirty.']);
    $this->put($route($other, $setup['beds'][1]), ['status' => 'dirty'])->assertNotFound();
    $this->put($route($setup['room'], $setup['beds'][1]), ['status' => 'inspected'])->assertForbidden();

    expect(bedStatusOf($setup['beds'][0]))->toBe(HousekeepingStatus::Dirty)
        ->and(dormRoomStatus($setup['room']))->toBe(HousekeepingStatus::Dirty);
});

test('the board costs the same queries however many dorm beds there are', function () {
    $setup = dormBedsSetup();
    $count = function () use ($setup): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($setup['owner'])->get(route('housekeeping.index', $setup['property']->code))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Model::preventLazyLoading();

    try {
        $count();
        $few = $count();
        Bed::factory()->count(5)->create(['room_id' => $setup['room']->id]);
        $room = Room::factory()->for($setup['property'])->create(['room_type_id' => $setup['room']->room_type_id]);
        Bed::factory()->count(4)->create(['room_id' => $room->id]);
        $count();
        $many = $count();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});
