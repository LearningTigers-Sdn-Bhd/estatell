<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `vacated_at` is the time a day use guest checked out before `ends_at`. The room is free from then on, so the
     * no-overlap constraint covers `starts_at` to `vacated_at`, or to `ends_at` while it is null.
     */
    public function up(): void
    {
        Schema::table('unit_day_uses', function (Blueprint $table): void {
            $table->time('vacated_at')->nullable();
        });

        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_vacated_at_check CHECK (vacated_at IS NULL OR (vacated_at >= starts_at AND vacated_at < ends_at))');
        DB::statement('ALTER TABLE unit_day_uses DROP CONSTRAINT unit_day_uses_no_overlap');
        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_no_overlap EXCLUDE USING gist (room_id WITH =, tsrange(date + starts_at, date + COALESCE(vacated_at, ends_at)) WITH &&) WHERE (room_id IS NOT NULL AND released_at IS NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE unit_day_uses DROP CONSTRAINT unit_day_uses_no_overlap');
        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_no_overlap EXCLUDE USING gist (room_id WITH =, tsrange(date + starts_at, date + ends_at) WITH &&) WHERE (room_id IS NOT NULL AND released_at IS NULL)');
        DB::statement('ALTER TABLE unit_day_uses DROP CONSTRAINT unit_day_uses_vacated_at_check');

        Schema::table('unit_day_uses', function (Blueprint $table): void {
            $table->dropColumn('vacated_at');
        });
    }
};
