<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A room or a bed can be on one unit night only. Cancel and no-show clear `room_id` and `bed_id`, so a unit that
     * does not hold inventory never counts. See docs/18-front-desk/02-room-and-bed-assignment.md.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX unit_nights_room_date_unique ON unit_nights (room_id, date) WHERE room_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX unit_nights_bed_date_unique ON unit_nights (bed_id, date) WHERE bed_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unit_nights_bed_date_unique');
        DB::statement('DROP INDEX IF EXISTS unit_nights_room_date_unique');
    }
};
