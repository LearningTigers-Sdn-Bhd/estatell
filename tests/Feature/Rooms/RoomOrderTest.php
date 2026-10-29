<?php

use App\Actions\Rooms\GenerateRooms;
use App\Actions\Rooms\ReorderRooms;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rooms\RoomNumberGenerator;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

test('generated rooms take positions in the typed order after the last room', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['number' => '101', 'position' => 4]);

    $numbers = (new RoomNumberGenerator)->parseList('205-206, 201')['numbers'];
    $plan = array_map(fn (string $number): array => ['number' => $number, 'group' => null], $numbers);

    app(GenerateRooms::class)($property, $owner, $type, null, $plan);

    expect($property->rooms()->ordered()->pluck('number')->all())->toBe(['101', '205', '206', '201'])
        ->and($property->rooms()->ordered()->pluck('position')->all())->toBe([4, 5, 6, 7]);
});

test('reordering puts the listed rooms first in one update and keeps the others after', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $rooms = collect(['101', '102', '103', '104'])
        ->map(fn (string $number, int $index) => Room::factory()->for($property)->create(['number' => $number, 'position' => $index + 1]));
    $archived = Room::factory()->for($property)->archived()->create(['number' => '100', 'position' => 9]);

    $updates = 0;
    DB::listen(function ($query) use (&$updates): void {
        if (preg_match('/^update "?rooms"? /i', $query->sql)) {
            $updates++;
        }
    });

    $reordered = app(ReorderRooms::class)($property, $owner, [$rooms[2]->id, $rooms[0]->id]);

    expect($reordered)->toBeTrue()
        ->and($updates)->toBe(1)
        ->and($property->rooms()->ordered()->pluck('number')->all())->toBe(['103', '101', '102', '104', '100'])
        ->and($property->rooms()->ordered()->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
        ->and($archived->fresh()->position)->toBe(5)
        ->and(Activity::query()->where('event', 'room.reordered')->sole()->properties['count'])->toBe(2);
});

test('reordering is refused for rooms that are not active rooms of the property', function (string $case) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = Room::factory()->for($property)->create(['number' => '101', 'position' => 1]);
    $other = match ($case) {
        'another property' => Room::factory()->create(),
        'archived' => Room::factory()->for($property)->archived()->create(),
        'repeated' => $room,
    };

    expect(app(ReorderRooms::class)($property, $owner, [$room->id, $other->id]))->toBeFalse()
        ->and($room->fresh()->position)->toBe(1)
        ->and(Activity::query()->where('event', 'room.reordered')->exists())->toBeFalse();
})->with(['another property', 'archived', 'repeated']);
