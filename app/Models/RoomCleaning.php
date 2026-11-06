<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RoomCleaningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time someone cleaned a room: who did it, when they went in and when they came out. A room has at most one
 * cleaning without an OUT time.
 *
 * @property int $id
 * @property int $property_id
 * @property int $room_id
 * @property CarbonImmutable $date The business date it counts on.
 * @property int $housekeeper_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'room_id', 'date', 'housekeeper_id', 'started_at', 'finished_at', 'created_by', 'updated_by'])]
class RoomCleaning extends Model
{
    /** @use HasFactory<RoomCleaningFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function housekeeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'housekeeper_id');
    }
}
