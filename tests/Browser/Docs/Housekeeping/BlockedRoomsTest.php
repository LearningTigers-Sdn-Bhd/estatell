<?php

use App\Actions\Housekeeping\SetNeedsMaintenance;
use App\Actions\Rooms\CreateRoomBlock;
use App\Data\Rooms\RoomBlockInputData;
use App\Enums\RoomBlockReason;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Block the first free Standard Double whole and the first free bed of the dorm, through the real Action, and flag a
 * third room. Returns the room number, the bed label, the flagged room number and the room left for the menu.
 *
 * @return array{room: string, until: string, bed: string, flagged: string, menu: string}
 */
$blockBoard = function (Property $property): array {
    $owner = User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail();
    $create = app(CreateRoomBlock::class);
    $rooms = Room::query()->where('property_id', $property->id)->where('number', '!=', 'D1')->orderBy('number')->get();

    $until = null;
    $blocked = $rooms->first(function (Room $room) use ($create, $owner, &$until): bool {
        foreach (['2026-10-18', '2026-10-17', '2026-10-16', '2026-10-15', '2026-10-14'] as $endsOn) {
            if ($create($owner, $room, null, new RoomBlockInputData(RoomBlockReason::Maintenance, '2026-10-14', $endsOn, 'Aircon is leaking.')) instanceof RoomBlock) {
                $until = CarbonImmutable::parse($endsOn)->format('j M');

                return true;
            }
        }

        return false;
    });
    $flagged = $rooms->firstWhere('number', '302');
    $menu = $rooms->whereNotIn('id', [$blocked->id, $flagged->id])->first();
    app(SetNeedsMaintenance::class)($owner, $flagged, true);

    $dorm = Room::query()->where('property_id', $property->id)->where('number', 'D1')->firstOrFail();
    $bed = Bed::query()->where('room_id', $dorm->id)->orderBy('id')->get()
        ->first(fn (Bed $candidate): bool => $create($owner, $dorm, $candidate, new RoomBlockInputData(RoomBlockReason::OutOfOrder, '2026-10-14', '2026-10-16', 'Broken ladder.')) instanceof RoomBlock);

    return ['room' => $blocked->number, 'until' => $until, 'bed' => $bed->label, 'flagged' => $flagged->number, 'menu' => $menu->number];
};

test('blocked room and bed block on the board', function () use ($blockBoard) {
    $made = $blockBoard($this->property);

    $page = $this->open(route('housekeeping.index', $this->property))
        ->waitForText('Aircon is leaking.')
        ->click('[aria-label="View"] button:first-child')
        ->wait(1);
    $page->script('document.querySelectorAll("*").forEach((node) => { node.scrollTop = node.scrollHeight; });');
    $page->wait(1);

    $this->shoot($page, 'blocked-room', badges: ['Blocked', "Bed {$made['bed']} blocked", 'Needs maintenance']);
});

test('block room in the row menu', function () use ($blockBoard) {
    $made = $blockBoard($this->property);

    $page = $this->open(route('housekeeping.index', $this->property))
        ->waitForText('Aircon is leaking.')
        ->click("[aria-label=\"Actions for room {$made['menu']}\"]")
        ->waitForText('Block room…')
        ->wait(1);

    $this->shoot($page, 'block-room-menu', badges: ['Block room…'], frame: '[role="menu"]');
});
