<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::ensureExtensionExists('btree_gist');

        Schema::create('unit_day_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_unit_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('hours');
            $table->decimal('amount', 12, 2);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['date', 'room_id']);
        });

        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_times_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_hours_check CHECK (hours BETWEEN 1 AND 24)');
        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_hours_match_check CHECK (ends_at - starts_at = make_interval(hours => hours))');
        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_no_overlap EXCLUDE USING gist (room_id WITH =, tsrange(date + starts_at, date + ends_at) WITH &&) WHERE (room_id IS NOT NULL AND released_at IS NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_day_uses');
    }
};
