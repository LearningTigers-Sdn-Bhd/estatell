<?php

use App\Actions\Housekeeping\UpdateBedStatus;
use App\Actions\Housekeeping\UpdateRoomStatus;
use App\Actions\NightAudit\Steps\UpdateRoomStatuses;
use App\Actions\Rooms\CreateBed;
use App\Data\Reports\HousekeepingTaskRow;
use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingRefusal;
use App\Enums\HousekeepingStatus;
use App\Enums\RemarkKind;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Housekeeping\HousekeepingBoardBuilder;
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

test('the board shows the booking\'s housekeeping remark on the guest\'s bed or room, and the printed sheet lists it', function () {
    $setup = dormBedsSetup();
    $private = Room::factory()->for($setup['property'])->create(['number' => '105']);
    $stay = function (array $night, string $remark) use ($setup, $private): void {
        $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
        ReservationRemark::factory()->for($reservation)->create(['kind' => RemarkKind::Housekeeping, 'body' => $remark]);
        ReservationRemark::factory()->for($reservation)->create(['kind' => RemarkKind::Dietary, 'body' => 'No pork']);
        $unit = ReservationUnit::factory()->withNights()->create([
            'reservation_id' => $reservation->id,
            'room_type_id' => isset($night['bed_id']) ? $setup['room']->room_type_id : $private->room_type_id,
            'arrival_date' => '2026-10-12',
            'departure_date' => '2026-10-14',
            'status' => UnitStatus::Confirmed,
        ]);
        $unit->nights()->update(['room_id' => null, 'bed_id' => null, ...$night]);
    };
    $stay(['bed_id' => $setup['beds'][1]->id], 'Lower bunk please');
    $stay(['room_id' => $private->id], 'Extra pillow');

    $this->actingAs($setup['owner'])->get(route('housekeeping.index', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rooms', function ($rooms) {
                $dorm = collect($rooms)->firstWhere('number', '201');
                $room = collect($rooms)->firstWhere('number', '105');

                return $dorm['guestRemark'] === null
                    && collect($dorm['beds'])->pluck('guestRemark', 'label')->all() === ['A1' => null, 'A2' => 'Lower bunk please', 'A3' => null]
                    && $room['guestRemark'] === 'Extra pillow';
            }));

    $rows = collect(app(HousekeepingBoardBuilder::class)->rows($setup['property'], CarbonImmutable::parse('2026-10-12'), false, false, false))
        ->keyBy('number');

    expect(HousekeepingTaskRow::fromRoom($rows['201'], 1)->remarks)->toBe('Bed A2: Lower bunk please')
        ->and(HousekeepingTaskRow::fromRoom($rows['105'], 2)->remarks)->toBe('Booking: Extra pillow');
});

test('a dorm row reads in counts, never as one bed\'s guest and dates, on the board and the printed sheet', function () {
    $setup = dormBedsSetup();
    [$a1, $a2, $a3] = $setup['beds'];
    $stay = function (Bed $bed, array $attributes) use ($setup): void {
        $unit = ReservationUnit::factory()->withNights()->create([
            'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id,
            'room_type_id' => $setup['room']->room_type_id,
            ...$attributes,
        ]);
        $unit->nights()->update(['room_id' => null, 'bed_id' => $bed->id]);
    };
    $stay($a1, ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12', 'departure_time' => '11:00', 'status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);
    $stay($a2, ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'arrival_time' => '15:00', 'status' => UnitStatus::Confirmed]);
    $stay($a3, ['arrival_date' => '2026-10-11', 'departure_date' => '2026-10-14', 'status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);

    $room = collect(app(HousekeepingBoardBuilder::class)->rows($setup['property'], CarbonImmutable::parse('2026-10-12'), false, false, false))
        ->firstWhere('number', '201');
    $printed = HousekeepingTaskRow::fromRoom($room, 1);

    expect($room->dorm->occupancy)->toBe('2 of 3 beds tonight')
        ->and(array_map(fn ($badge) => $badge->label, $room->dorm->movements))->toBe(['1 Due out', '1 Arriving', '1 In house'])
        ->and($room->dorm->arrivals)->toBe('1 from 15:00')
        ->and($room->dorm->departures)->toBe('1 by 11:00')
        ->and([$printed->guest, $printed->stay, $printed->arrival, $printed->departure])
        ->toBe(['2 of 3 beds tonight', '1 Due out · 1 Arriving · 1 In house', '1 from 15:00', '1 by 11:00']);

    $empty = Room::factory()->for($setup['property'])->create(['number' => '202', 'room_type_id' => $setup['room']->room_type_id]);
    Bed::factory()->create(['room_id' => $empty->id]);
    $quiet = collect(app(HousekeepingBoardBuilder::class)->rows($setup['property'], CarbonImmutable::parse('2026-10-12'), false, false, false))
        ->firstWhere('number', '202');

    expect($quiet->dorm->occupancy)->toBe('0 of 1 bed tonight')
        ->and($quiet->dorm->movements)->toBe([])
        ->and($quiet->dorm->arrivals)->toBeNull();
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
