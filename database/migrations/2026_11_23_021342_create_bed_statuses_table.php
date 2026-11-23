<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The housekeeping status of each dorm bed: only the bunk a guest leaves needs making up, not the whole dorm. The
 * dorm's room status (`room_statuses`) becomes a summary of its beds. Every existing bed starts with its room's
 * status. See docs/19-housekeeping/04-dorm-beds.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bed_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('bed_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('clean');
            $table->foreignId('last_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE bed_statuses ADD CONSTRAINT bed_statuses_status_check CHECK (status IN ('clean', 'dirty', 'inspected'))");
        DB::statement("INSERT INTO bed_statuses (property_id, bed_id, status, created_at, updated_at) SELECT rooms.property_id, beds.id, COALESCE(room_statuses.status, 'clean'), now(), now() FROM beds JOIN rooms ON rooms.id = beds.room_id LEFT JOIN room_statuses ON room_statuses.room_id = rooms.id");
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_statuses');
    }
};
